"""Focused, executable assertions for the accepted MVP inter-module contracts.

Run from the repository root with ``python3 -m unittest discover -s tests/contracts``.
The suite uses the contract owner's loader/validator so external JSON Schema
references are interpreted the same way as in validate_contracts.py.
"""

import copy
import json
import re
import sys
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
CONTRACTS = ROOT / "contracts"
sys.path.insert(0, str(CONTRACTS / "tools"))

from validate_contracts import load_doc, validate  # noqa: E402


class MvpBoundaryContracts(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.public_path = CONTRACTS / "public-api.openapi.yaml"
        cls.ai_path = CONTRACTS / "ai-api.openapi.yaml"
        cls.public = load_doc(cls.public_path)
        cls.ai = load_doc(cls.ai_path)

    def assert_valid(self, value, schema, base):
        self.assertEqual([], validate(value, schema, base), value)

    def assert_invalid(self, value, schema, base):
        self.assertNotEqual([], validate(value, schema, base), value)

    def test_accepted_operations_cover_acceptance_and_failure_paths(self):
        self.assertEqual("accepted", self.public["info"]["x-contract-status"])
        self.assertEqual("accepted", self.ai["info"]["x-contract-status"])
        self.assertEqual(self.public["info"]["version"], self.ai["info"]["version"])

        expected = {
            ("/api/auth/login", "post"): {"200", "422"},
            ("/api/admin/batches", "post"): {"201"},
            ("/api/admin/batches/{batch}", "get"): {"200"},
            ("/api/admin/batches/{batch}/images", "post"): {"201", "409"},
            ("/api/admin/batches/{batch}/complete", "post"): {"200"},
            ("/api/admin/images/{image}", "get"): {"200"},
            ("/api/admin/images/{image}/tags", "post"): {"201"},
            ("/api/admin/images/{image}/tags/{tag}", "delete"): {"204"},
            ("/api/admin/images/{image}/retry", "post"): {"202", "409"},
            ("/api/admin/images/{image}/reprocess", "post"): {"202"},
            ("/api/admin/tag-candidates", "get"): {"200"},
            ("/api/admin/tag-candidates/{candidate}/approve", "post"): {"200"},
            ("/api/admin/tag-candidates/{candidate}/merge", "post"): {"200"},
            ("/api/search", "post"): {"200", "503"},
        }
        for (path, method), statuses in expected.items():
            with self.subTest(path=path, method=method):
                self.assertTrue(statuses <= set(self.public["paths"][path][method]["responses"]))

        self.assertEqual({"200", "400", "401", "413", "415", "422", "429", "500", "502", "503"},
                         set(self.ai["paths"]["/v1/images/analyze"]["post"]["responses"]))

    def test_ai_analysis_is_strict_and_cannot_return_canonical_tag_ids(self):
        schema_path = CONTRACTS / "schemas/image-analysis.json"
        schema = load_doc(schema_path)
        fixture = json.loads((CONTRACTS / "examples/ai/analyze-response.json").read_text())
        self.assert_valid(fixture, schema, schema_path)
        self.assertEqual(1152, len(fixture["embedding"]))

        with_tag_id = copy.deepcopy(fixture)
        with_tag_id["concepts"][0]["tag_id"] = 14
        self.assert_invalid(with_tag_id, schema, schema_path)

        short_embedding = copy.deepcopy(fixture)
        short_embedding["embedding"] = [0.1, 0.2]
        self.assert_invalid(short_embedding, schema, schema_path)

    def test_search_modes_preserve_tag_filter_and_ai_outage_contract(self):
        request = self.public["components"]["schemas"]["SearchRequest"]
        self.assert_valid({"mode": "tags", "tag_ids": [14]}, request, self.public_path)
        self.assert_valid({"mode": "semantic", "query": "мужчина в каске на промышленном объекте"},
                          request, self.public_path)
        self.assert_valid({"mode": "hybrid", "query": "завод", "tag_ids": [14]},
                          request, self.public_path)
        self.assert_invalid({"mode": "tags", "tag_ids": [14], "query": "завод"}, request, self.public_path)
        self.assert_invalid({"mode": "hybrid", "query": "завод"}, request, self.public_path)

        error_path = CONTRACTS / "schemas/error-response.json"
        error_schema = load_doc(error_path)
        outage = json.loads((CONTRACTS / "examples/public-api/errors/ai-service-unavailable.json").read_text())
        self.assert_valid(outage, error_schema, error_path)
        self.assertEqual("AI_SERVICE_UNAVAILABLE", outage["error"]["code"])

    def test_duplicate_error_carries_existing_image_id(self):
        schema_path = CONTRACTS / "schemas/error-response.json"
        duplicate = json.loads((CONTRACTS / "examples/public-api/errors/image-already-exists.json").read_text())
        self.assert_valid(duplicate, load_doc(schema_path), schema_path)
        self.assertIsInstance(duplicate["error"]["details"]["existing_image_id"], int)

    def test_nuxt_calls_only_declared_public_operations(self):
        call = re.compile(r"\bapi\.(get|post|patch|delete)(?:<[^>\n]+>)?\s*\(\s*([`'\"])(.*?)\2", re.S)
        found = 0
        for source in (ROOT / "apps/admin").rglob("*"):
            if source.suffix not in (".ts", ".vue") or "tests" in source.parts:
                continue
            for match in call.finditer(source.read_text()):
                found += 1
                method, path = match.group(1), match.group(3)
                actual = path if path.startswith("/sanctum/") else "/api" + path
                actual_parts = actual.split("/")
                compatible = any(
                    method in operations
                    and len(parts := declared.split("/")) == len(actual_parts)
                    and all(
                        part == observed
                        or (part.startswith("{") and part.endswith("}")
                            and observed.startswith("${") and observed.endswith("}"))
                        for part, observed in zip(parts, actual_parts)
                    )
                    for declared, operations in self.public["paths"].items()
                )
                self.assertTrue(compatible, f"{source.relative_to(ROOT)} calls undeclared {method.upper()} {actual}")
        self.assertGreater(found, 25, "Frontend API call scan unexpectedly found too few calls")

    def test_nuxt_tag_page_size_obeys_public_contract(self):
        page = (ROOT / "apps/admin/pages/tags.vue").read_text()
        request = re.search(r"api\.get\('/admin/tags',\s*\{\s*params:\s*\{\s*per_page:\s*(\d+)", page)
        self.assertIsNotNone(request, "Tag page's direct list request was not found")
        params = self.public["paths"]["/api/admin/tags"]["get"]["parameters"]
        max_page_size = next(p["schema"]["maximum"] for p in params if p.get("name") == "per_page")
        self.assertLessEqual(int(request.group(1)), max_page_size,
                             "Nuxt tags page requests more rows than Laravel's accepted contract permits")


if __name__ == "__main__":
    unittest.main()
