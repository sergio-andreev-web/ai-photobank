#!/usr/bin/env python3
"""Проверка контрактов AI Photobank.

Запуск: python3 contracts/tools/validate_contracts.py

Проверяет:
  * JSON Schema файлы в contracts/schemas (структура, ссылки, известные ключевые слова);
  * OpenAPI 3.1 документы: обязательные поля, уникальные operationId, path-параметры,
    разрешимость всех $ref (внутренних и межфайловых), схемы безопасности, теги;
  * все inline-примеры и externalValue-примеры OpenAPI против схем соответствующих media types;
  * файлы contracts/examples/** против соответствующих схем;
  * наличие обязательных операций пакета 00 (auth, batch/upload, image/media, taxonomy/aliases,
    candidates, search, retry, health/readiness);
  * негативные проверки: заведомо неверные payload обязаны не пройти валидацию.

Зависимости: только стандартная библиотека. YAML читается через PyYAML, если он установлен,
иначе через системный Ruby (Psych). Если установлены jsonschema и openapi-spec-validator,
дополнительно выполняются их проверки (check_schema / validate). Встроенный валидатор реализует
подмножество JSON Schema 2020-12, используемое контрактами (см. SUPPORTED_KEYWORDS);
незнакомое ключевое слово в схеме считается ошибкой, чтобы подмножество не расходилось молча.
"""
from __future__ import annotations

import copy
import json
import re
import shutil
import subprocess
import sys
from pathlib import Path
from typing import Any, Callable, Dict, List, Optional, Tuple

ROOT = Path(__file__).resolve().parent.parent
SCHEMAS_DIR = ROOT / "schemas"
EXAMPLES_DIR = ROOT / "examples"
OPENAPI_FILES = [ROOT / "public-api.openapi.yaml", ROOT / "ai-api.openapi.yaml", ROOT / "web-research.openapi.yaml"]

SUPPORTED_KEYWORDS = {
    # validation
    "$ref", "type", "enum", "const", "properties", "required", "additionalProperties",
    "items", "minItems", "maxItems", "uniqueItems", "minLength", "maxLength", "pattern",
    "minimum", "maximum", "minProperties", "oneOf", "anyOf", "allOf", "not",
    # annotations / ignored for validation
    "$schema", "$id", "$defs", "title", "description", "default", "examples", "format",
    "contentMediaType", "discriminator", "deprecated", "readOnly", "writeOnly",
}
HTTP_METHODS = {"get", "put", "post", "delete", "patch", "head", "options", "trace"}

errors: List[str] = []
notes: List[str] = []


def fail(msg: str) -> None:
    errors.append(msg)


# --------------------------------------------------------------------------- loading

def load_yaml(path: Path) -> Any:
    try:
        import yaml  # type: ignore

        with path.open(encoding="utf-8") as fh:
            return yaml.safe_load(fh)
    except ImportError:
        pass
    ruby = shutil.which("ruby")
    if not ruby:
        raise SystemExit("Нужен PyYAML или ruby для чтения YAML")
    script = "require 'yaml'; require 'json'; print JSON.generate(YAML.safe_load(File.read(ARGV[0]), [], [], true))"
    out = subprocess.run([ruby, "-e", script, str(path)], capture_output=True, text=True, check=False)
    if out.returncode != 0:
        raise SystemExit(f"YAML parse error in {path}: {out.stderr.strip()}")
    return json.loads(out.stdout)


_docs: Dict[Path, Any] = {}


def load_doc(path: Path) -> Any:
    path = path.resolve()
    if path not in _docs:
        if path.suffix in (".yaml", ".yml"):
            _docs[path] = load_yaml(path)
        else:
            _docs[path] = json.loads(path.read_text(encoding="utf-8"))
    return _docs[path]


def pointer_get(doc: Any, pointer: str) -> Any:
    if pointer in ("", "#"):
        return doc
    if not pointer.startswith("/"):
        raise KeyError(f"unsupported pointer {pointer!r}")
    node = doc
    for raw in pointer[1:].split("/"):
        part = raw.replace("~1", "/").replace("~0", "~")
        if isinstance(node, list):
            node = node[int(part)]
        elif isinstance(node, dict) and part in node:
            node = node[part]
        else:
            raise KeyError(pointer)
    return node


def resolve_ref(ref: str, base: Path) -> Tuple[Any, Path]:
    file_part, _, pointer = ref.partition("#")
    target = (base.parent / file_part).resolve() if file_part else base.resolve()
    if not target.exists():
        raise KeyError(f"file not found: {file_part}")
    return pointer_get(load_doc(target), pointer), target


# --------------------------------------------------------------------------- validator

_TYPE_CHECKS: Dict[str, Callable[[Any], bool]] = {
    "null": lambda v: v is None,
    "boolean": lambda v: isinstance(v, bool),
    "object": lambda v: isinstance(v, dict),
    "array": lambda v: isinstance(v, list),
    "string": lambda v: isinstance(v, str),
    "number": lambda v: isinstance(v, (int, float)) and not isinstance(v, bool),
    "integer": lambda v: (isinstance(v, int) and not isinstance(v, bool))
    or (isinstance(v, float) and v.is_integer()),
}


def _json_equal(a: Any, b: Any) -> bool:
    return json.dumps(a, sort_keys=True) == json.dumps(b, sort_keys=True)


def validate(instance: Any, schema: Any, base: Path, path: str = "$") -> List[str]:
    """Возвращает список ошибок (пустой — валидно)."""
    if schema is True:
        return []
    if schema is False:
        return [f"{path}: schema false"]
    if not isinstance(schema, dict):
        return [f"{path}: invalid schema node {schema!r}"]
    errs: List[str] = []
    if "$ref" in schema:
        try:
            target, tbase = resolve_ref(schema["$ref"], base)
        except (KeyError, ValueError, IndexError) as exc:
            return [f"{path}: unresolved $ref {schema['$ref']}: {exc}"]
        errs += validate(instance, target, tbase, path)
    if "type" in schema:
        types = schema["type"] if isinstance(schema["type"], list) else [schema["type"]]
        if not any(_TYPE_CHECKS[t](instance) for t in types):
            return errs + [f"{path}: expected type {types}, got {type(instance).__name__}"]
    if "enum" in schema and not any(_json_equal(instance, e) for e in schema["enum"]):
        errs.append(f"{path}: {instance!r} not in enum {schema['enum']}")
    if "const" in schema and not _json_equal(instance, schema["const"]):
        errs.append(f"{path}: {instance!r} != const {schema['const']!r}")
    if isinstance(instance, str):
        if "minLength" in schema and len(instance) < schema["minLength"]:
            errs.append(f"{path}: shorter than {schema['minLength']}")
        if "maxLength" in schema and len(instance) > schema["maxLength"]:
            errs.append(f"{path}: longer than {schema['maxLength']}")
        if "pattern" in schema and not re.search(schema["pattern"], instance):
            errs.append(f"{path}: does not match /{schema['pattern']}/")
    if _TYPE_CHECKS["number"](instance):
        if "minimum" in schema and instance < schema["minimum"]:
            errs.append(f"{path}: {instance} < minimum {schema['minimum']}")
        if "maximum" in schema and instance > schema["maximum"]:
            errs.append(f"{path}: {instance} > maximum {schema['maximum']}")
    if isinstance(instance, list):
        if "minItems" in schema and len(instance) < schema["minItems"]:
            errs.append(f"{path}: {len(instance)} items < minItems {schema['minItems']}")
        if "maxItems" in schema and len(instance) > schema["maxItems"]:
            errs.append(f"{path}: {len(instance)} items > maxItems {schema['maxItems']}")
        if schema.get("uniqueItems"):
            seen = [json.dumps(i, sort_keys=True) for i in instance]
            if len(seen) != len(set(seen)):
                errs.append(f"{path}: items are not unique")
        if "items" in schema:
            for i, item in enumerate(instance):
                errs += validate(item, schema["items"], base, f"{path}[{i}]")
    if isinstance(instance, dict):
        props = schema.get("properties", {})
        for req in schema.get("required", []):
            if req not in instance:
                errs.append(f"{path}: missing required '{req}'")
        if "minProperties" in schema and len(instance) < schema["minProperties"]:
            errs.append(f"{path}: fewer than {schema['minProperties']} properties")
        for key, value in instance.items():
            if key in props:
                errs += validate(value, props[key], base, f"{path}.{key}")
            elif "additionalProperties" in schema:
                errs += validate(value, schema["additionalProperties"], base, f"{path}.{key}")
    for sub in schema.get("allOf", []):
        errs += validate(instance, sub, base, path)
    if "anyOf" in schema:
        if not any(not validate(instance, s, base, path) for s in schema["anyOf"]):
            errs.append(f"{path}: matches none of anyOf")
    if "oneOf" in schema:
        matches = sum(1 for s in schema["oneOf"] if not validate(instance, s, base, path))
        if matches != 1:
            errs.append(f"{path}: matches {matches} of oneOf (expected exactly 1)")
    if "not" in schema and not validate(instance, schema["not"], base, path):
        errs.append(f"{path}: must not match 'not' schema")
    return errs


# --------------------------------------------------------------------------- schema structure

def check_schema_node(node: Any, base: Path, where: str) -> None:
    """Рекурсивно проверяет, что схема использует только поддерживаемые ключевые слова и ссылки разрешимы."""
    if isinstance(node, bool):
        return
    if not isinstance(node, dict):
        fail(f"{where}: schema must be object or boolean")
        return
    for key, value in node.items():
        if key.startswith("x-"):
            continue
        if key not in SUPPORTED_KEYWORDS:
            fail(f"{where}: unsupported keyword '{key}'")
            continue
        if key == "$ref":
            try:
                target, tbase = resolve_ref(value, base)
            except (KeyError, ValueError, IndexError) as exc:
                fail(f"{where}: unresolved $ref {value}: {exc}")
        elif key == "type":
            types = value if isinstance(value, list) else [value]
            for t in types:
                if t not in _TYPE_CHECKS:
                    fail(f"{where}: unknown type {t!r}")
        elif key == "pattern":
            try:
                re.compile(value)
            except re.error as exc:
                fail(f"{where}: bad pattern {value!r}: {exc}")
        elif key in ("properties", "$defs"):
            for name, sub in value.items():
                check_schema_node(sub, base, f"{where}/{key}/{name}")
        elif key in ("items", "additionalProperties", "not"):
            check_schema_node(value, base, f"{where}/{key}")
        elif key in ("allOf", "anyOf", "oneOf"):
            for i, sub in enumerate(value):
                check_schema_node(sub, base, f"{where}/{key}/{i}")
        elif key == "required":
            props = node.get("properties")
            if props is not None and not node.get("allOf"):
                for req in value:
                    if req not in props:
                        fail(f"{where}: required '{req}' not declared in properties")
        elif key in ("enum", "examples") and not isinstance(value, list):
            fail(f"{where}: {key} must be array")


def check_json_schemas() -> None:
    for path in sorted(SCHEMAS_DIR.glob("*.json")):
        doc = load_doc(path)
        rel = path.relative_to(ROOT)
        if doc.get("$schema") != "https://json-schema.org/draft/2020-12/schema":
            fail(f"{rel}: $schema must be draft 2020-12")
        if not str(doc.get("$id", "")).endswith("/" + path.name):
            fail(f"{rel}: $id must end with /{path.name}")
        check_schema_node(doc, path, str(rel))


# --------------------------------------------------------------------------- OpenAPI structure

def walk_refs(node: Any, base: Path, where: str) -> None:
    if isinstance(node, dict):
        ref = node.get("$ref")
        if isinstance(ref, str):
            try:
                resolve_ref(ref, base)
            except (KeyError, ValueError, IndexError) as exc:
                fail(f"{where}: unresolved $ref {ref}: {exc}")
        for key, value in node.items():
            # значения примеров — данные, а не схемы: $ref внутри них OpenAPI не разрешает
            if key in ("value", "example"):
                if isinstance(value, (dict, list)) and "$ref" in json.dumps(value):
                    fail(f"{where}/{key}: $ref inside example data is not resolved by OpenAPI")
                continue
            walk_refs(value, base, f"{where}/{key}")
    elif isinstance(node, list):
        for i, item in enumerate(node):
            walk_refs(item, base, f"{where}/{i}")


def deref(node: Any, base: Path) -> Tuple[Any, Path]:
    seen = 0
    while isinstance(node, dict) and "$ref" in node and seen < 20:
        node, base = resolve_ref(node["$ref"], base)
        seen += 1
    return node, base


def iter_operations(doc: Dict[str, Any]):
    for p, item in (doc.get("paths") or {}).items():
        for method, op in item.items():
            if method in HTTP_METHODS:
                yield p, method, item, op


def collect_examples(content: Dict[str, Any], base: Path, where: str):
    """Yield (instance, schema, schema_base, label) для всех примеров media types."""
    for media, mt in (content or {}).items():
        schema = mt.get("schema")
        if schema is None or not media.endswith("json"):
            continue
        if "example" in mt:
            yield mt["example"], schema, base, f"{where} {media} example"
        for name, ex in (mt.get("examples") or {}).items():
            ex, ex_base = deref(ex, base)
            if "value" in ex:
                yield ex["value"], schema, base, f"{where} {media} examples.{name}"
            elif "externalValue" in ex:
                ext = (ex_base.parent / ex["externalValue"]).resolve()
                if not ext.exists():
                    fail(f"{where}: externalValue not found {ex['externalValue']}")
                    continue
                yield json.loads(ext.read_text(encoding="utf-8")), schema, base, f"{where} {media} examples.{name} ({ext.name})"


def check_openapi(path: Path) -> List[Tuple[str, str]]:
    rel = path.relative_to(ROOT)
    doc = load_doc(path)
    if not str(doc.get("openapi", "")).startswith("3.1."):
        fail(f"{rel}: openapi must be 3.1.x")
    info = doc.get("info") or {}
    for key in ("title", "version"):
        if not info.get(key):
            fail(f"{rel}: info.{key} is required")
    walk_refs(doc, path, str(rel))

    comps = doc.get("components") or {}
    for name, schema in (comps.get("schemas") or {}).items():
        check_schema_node(schema, path, f"{rel}#/components/schemas/{name}")

    declared_tags = {t["name"] for t in doc.get("tags", [])}
    schemes = set((comps.get("securitySchemes") or {}).keys())
    op_ids: Dict[str, str] = {}
    ops: List[Tuple[str, str]] = []

    def check_security(reqs: Any, where: str) -> None:
        for req in reqs or []:
            for scheme in req:
                if scheme not in schemes:
                    fail(f"{where}: unknown security scheme {scheme}")

    check_security(doc.get("security"), f"{rel} security")

    for p, method, item, op in iter_operations(doc):
        where = f"{rel} {method.upper()} {p}"
        ops.append((method, p))
        if not p.startswith("/"):
            fail(f"{where}: path must start with /")
        op_id = op.get("operationId")
        if not op_id:
            fail(f"{where}: operationId required")
        elif op_id in op_ids:
            fail(f"{where}: duplicate operationId {op_id} (also {op_ids[op_id]})")
        else:
            op_ids[op_id] = where
        for tag in op.get("tags", []):
            if tag not in declared_tags:
                fail(f"{where}: tag {tag} not declared")
        if not op.get("responses"):
            fail(f"{where}: responses required")
        check_security(op.get("security"), where)

        params = []
        for prm in list(item.get("parameters", [])) + list(op.get("parameters", [])):
            prm, _ = deref(prm, path)
            params.append(prm)
        path_params = {prm["name"] for prm in params if prm.get("in") == "path"}
        template = set(re.findall(r"{([^}]+)}", p))
        if path_params != template:
            fail(f"{where}: path params {sorted(path_params)} != template {sorted(template)}")
        for prm in params:
            if prm.get("in") == "path" and prm.get("required") is not True:
                fail(f"{where}: path param {prm.get('name')} must be required")

        body = op.get("requestBody")
        if body:
            body, _ = deref(body, path)
            for inst, schema, sbase, label in collect_examples(body.get("content"), path, f"{where} request"):
                for err in validate(inst, schema, sbase):
                    fail(f"{label}: {err}")
        for code, resp in (op.get("responses") or {}).items():
            resp, _ = deref(resp, path)
            if not re.fullmatch(r"[1-5](\d\d|XX)|default", str(code)):
                fail(f"{where}: bad response code {code}")
            if not resp.get("description"):
                fail(f"{where} {code}: description required")
            for inst, schema, sbase, label in collect_examples(resp.get("content"), path, f"{where} {code}"):
                for err in validate(inst, schema, sbase):
                    fail(f"{label}: {err}")
    return ops


# --------------------------------------------------------------------------- example files & invariants

def schema_at(ref: str) -> Tuple[Any, Path]:
    return resolve_ref(ref, SCHEMAS_DIR / "_")


EXAMPLE_FILE_SCHEMAS = {
    "research/uncertain.json": "object-research.json",
    "ai/analyze-response.json": "image-analysis.json",
    "ai/text-embeddings-request.json": "text-embeddings.json#/$defs/TextEmbeddingsRequest",
    "ai/text-embeddings-response.json": "text-embeddings.json#/$defs/TextEmbeddingsResponse",
    "ai/errors/invalid-request.json": "ai-error.json",
    "ai/errors/unauthorized.json": "ai-error.json",
    "ai/errors/image-too-large.json": "ai-error.json",
    "ai/errors/unsupported-media-type.json": "ai-error.json",
    "ai/errors/image-decode-failed.json": "ai-error.json",
    "ai/errors/model-not-ready.json": "ai-error.json",
    "ai/errors/model-output-invalid.json": "ai-error.json",
    "ai/errors/service-busy.json": "ai-error.json",
    "ai/errors/inference-failed.json": "ai-error.json",
    "public-api/errors/unauthenticated.json": "error-response.json",
    "public-api/errors/validation-error.json": "error-response.json",
    "public-api/errors/image-not-found.json": "error-response.json",
    "public-api/errors/image-already-exists.json": "error-response.json",
    "public-api/errors/ai-service-unavailable.json": "error-response.json",
    "public-api/errors/internal-error.json": "error-response.json",
    "public-api/errors/not-found.json": "error-response.json",
    "public-api/errors/method-not-allowed.json": "error-response.json",
}


def check_example_files() -> None:
    for rel, ref in EXAMPLE_FILE_SCHEMAS.items():
        path = EXAMPLES_DIR / rel
        if not path.exists():
            fail(f"examples/{rel}: missing")
            continue
        schema, base = schema_at(ref)
        for err in validate(json.loads(path.read_text(encoding="utf-8")), schema, base):
            fail(f"examples/{rel}: {err}")
    listed = set(EXAMPLE_FILE_SCHEMAS)
    for path in EXAMPLES_DIR.rglob("*.json"):
        if str(path.relative_to(EXAMPLES_DIR)) not in listed:
            fail(f"{path.relative_to(ROOT)}: example file has no schema mapping in validate_contracts.py")

    resp = json.loads((EXAMPLES_DIR / "ai/text-embeddings-response.json").read_text(encoding="utf-8"))
    req = json.loads((EXAMPLES_DIR / "ai/text-embeddings-request.json").read_text(encoding="utf-8"))
    if len(resp["embeddings"]) != len(req["texts"]):
        fail("examples/ai: embeddings count must equal texts count")


def negative_checks() -> None:
    """Неверные payload обязаны отклоняться — защищает от «слишком мягкого» валидатора или схемы."""
    analysis_schema, base = schema_at("image-analysis.json")
    good = json.loads((EXAMPLES_DIR / "ai/analyze-response.json").read_text(encoding="utf-8"))

    def mutate(fn: Callable[[Dict[str, Any]], None]) -> Dict[str, Any]:
        bad = copy.deepcopy(good)
        fn(bad)
        return bad

    cases = {
        "embedding with 2 numbers (shortened §31 example)": mutate(lambda d: d.__setitem__("embedding", [0.123, -0.551])),
        "embedding_dimensions != 1152": mutate(lambda d: d.__setitem__("embedding_dimensions", 768)),
        "unknown concept type": mutate(lambda d: d["concepts"][0].__setitem__("type", "animal")),
        "concept confidence > 1": mutate(lambda d: d["concepts"][0].__setitem__("confidence", 1.2)),
        "empty concept label": mutate(lambda d: d["concepts"][0].__setitem__("label", "")),
        "tag_id leaked into concept": mutate(lambda d: d["concepts"][0].__setitem__("tag_id", 14)),
        "missing request_id": mutate(lambda d: d.pop("request_id")),
        "extra top-level field": mutate(lambda d: d.__setitem__("tags", [])),
        "non-numeric embedding item": mutate(lambda d: d["embedding"].__setitem__(0, "x")),
    }
    for label, payload in cases.items():
        if not validate(payload, analysis_schema, base):
            fail(f"negative check passed unexpectedly: image-analysis {label}")

    err_schema, ebase = schema_at("ai-error.json")
    fastapi_default = {"detail": [{"loc": ["body", "image"], "msg": "field required", "type": "missing"}]}
    if not validate(fastapi_default, err_schema, ebase):
        fail("negative check passed unexpectedly: FastAPI default error body accepted by ai-error.json")

    public = load_doc(ROOT / "public-api.openapi.yaml")
    ppath = (ROOT / "public-api.openapi.yaml").resolve()
    search = public["components"]["schemas"]["SearchRequest"]
    search_cases = {
        "tags mode without tag_ids": {"mode": "tags"},
        "tags mode with query": {"mode": "tags", "tag_ids": [1], "query": "x"},
        "semantic without query": {"mode": "semantic"},
        "semantic whitespace query": {"mode": "semantic", "query": "   "},
        "hybrid without tag_ids": {"mode": "hybrid", "query": "x"},
        "unknown mode": {"mode": "vector", "query": "x"},
        "missing mode": {"query": "x", "tag_ids": [1]},
        "per_page over limit": {"mode": "semantic", "query": "x", "per_page": 1000},
    }
    for label, payload in search_cases.items():
        if not validate(payload, search, ppath):
            fail(f"negative check passed unexpectedly: search {label}")
    for label, payload in {
        "tags": {"mode": "tags", "tag_ids": [12]},
        "semantic+debug": {"mode": "semantic", "query": "каска", "debug": True},
        "hybrid": {"mode": "hybrid", "query": "каска", "tag_ids": [14, 87], "page": 2, "per_page": 50},
    }.items():
        for err in validate(payload, search, ppath):
            fail(f"positive search case {label}: {err}")


REQUIRED_PUBLIC_OPS = [
    ("post", "/api/auth/login"), ("post", "/api/auth/logout"), ("get", "/api/auth/me"),
    ("post", "/api/admin/batches"), ("get", "/api/admin/batches"), ("get", "/api/admin/batches/{batch}"),
    ("post", "/api/admin/batches/{batch}/images"), ("post", "/api/admin/batches/{batch}/complete"),
    ("get", "/api/admin/images"), ("get", "/api/admin/images/{image}"), ("patch", "/api/admin/images/{image}"),
    ("delete", "/api/admin/images/{image}"), ("post", "/api/admin/images/{image}/reprocess"),
    ("post", "/api/admin/images/{image}/retry"),
    ("post", "/api/admin/images/{image}/tags"), ("delete", "/api/admin/images/{image}/tags/{tag}"),
    ("get", "/api/admin/tags"), ("post", "/api/admin/tags"), ("get", "/api/admin/tags/{tag}"),
    ("patch", "/api/admin/tags/{tag}"), ("delete", "/api/admin/tags/{tag}"),
    ("post", "/api/admin/tags/{tag}/aliases"), ("delete", "/api/admin/tags/{tag}/aliases/{alias}"),
    ("get", "/api/admin/tag-candidates"), ("post", "/api/admin/tag-candidates/{candidate}/approve"),
    ("post", "/api/admin/tag-candidates/{candidate}/merge"), ("post", "/api/admin/tag-candidates/{candidate}/reject"),
    ("post", "/api/search"), ("get", "/api/media/images/{image}/{variant}"), ("get", "/api/admin/dashboard"),
]
REQUIRED_AI_OPS = [
    ("get", "/health"), ("get", "/ready"), ("post", "/v1/images/analyze"), ("post", "/v1/embeddings/text"),
]


def check_required(ops: List[Tuple[str, str]], required: List[Tuple[str, str]], label: str) -> None:
    have = set(ops)
    for op in required:
        if op not in have:
            fail(f"{label}: required operation {op[0].upper()} {op[1]} missing")


# --------------------------------------------------------------------------- optional third-party

def _package_version(name: str) -> str:
    try:
        from importlib.metadata import version

        return version(name)
    except Exception:  # noqa: BLE001
        return "?"


def optional_library_checks() -> None:
    try:
        import jsonschema  # type: ignore
        from referencing import Registry, Resource  # type: ignore

        resources = []
        for path in sorted(SCHEMAS_DIR.glob("*.json")):
            doc = load_doc(path)
            try:
                jsonschema.Draft202012Validator.check_schema(doc)
            except jsonschema.SchemaError as exc:
                fail(f"jsonschema check_schema {path.name}: {exc.message}")
            resources.append((doc["$id"], Resource.from_contents(doc)))
        registry = Registry().with_resources(resources)
        base_id = load_doc(SCHEMAS_DIR / "enums.json")["$id"].rsplit("/", 1)[0] + "/"

        def lib_errors(instance: Any, ref: str) -> List[str]:
            validator = jsonschema.Draft202012Validator({"$ref": base_id + ref}, registry=registry)
            return [e.message for e in validator.iter_errors(instance)]

        for rel, ref in EXAMPLE_FILE_SCHEMAS.items():
            for msg in lib_errors(json.loads((EXAMPLES_DIR / rel).read_text(encoding="utf-8")), ref):
                fail(f"jsonschema examples/{rel}: {msg[:200]}")
        good = json.loads((EXAMPLES_DIR / "ai/analyze-response.json").read_text(encoding="utf-8"))
        bad_short = dict(good, embedding=[0.123, -0.551])
        bad_type = copy.deepcopy(good)
        bad_type["concepts"][0]["type"] = "animal"
        for label, bad in {"2-number embedding": bad_short, "unknown concept type": bad_type}.items():
            if not lib_errors(bad, "image-analysis.json"):
                fail(f"jsonschema negative check passed unexpectedly: {label}")
        notes.append(f"jsonschema {_package_version('jsonschema')}: check_schema, примеры и негативные проверки выполнены")
    except ImportError:
        notes.append("jsonschema не установлен: метасхема не проверена библиотекой (только встроенные проверки)")
    try:
        from openapi_spec_validator import validate as osv_validate  # type: ignore
        from openapi_spec_validator.readers import read_from_filename  # type: ignore

        for path in OPENAPI_FILES:
            spec, base_uri = read_from_filename(str(path))
            try:
                osv_validate(spec, base_uri=base_uri)
            except Exception as exc:  # noqa: BLE001 — сообщение библиотеки переносим в отчет
                fail(f"openapi-spec-validator {path.name}: {exc}")
        notes.append(f"openapi-spec-validator {_package_version('openapi-spec-validator')}: validate выполнен")
    except ImportError:
        notes.append("openapi-spec-validator не установлен: OpenAPI проверен только встроенными правилами")


def main() -> int:
    check_json_schemas()
    public_ops = check_openapi(ROOT / "public-api.openapi.yaml")
    ai_ops = check_openapi(ROOT / "ai-api.openapi.yaml")
    check_required(public_ops, REQUIRED_PUBLIC_OPS, "public-api")
    check_required(ai_ops, REQUIRED_AI_OPS, "ai-api")
    check_openapi(ROOT / "web-research.openapi.yaml")
    check_example_files()
    negative_checks()
    optional_library_checks()

    for note in notes:
        print(f"note: {note}")
    if errors:
        for err in errors:
            print(f"ERROR: {err}")
        print(f"FAILED: {len(errors)} error(s)")
        return 1
    print(f"OK: {len(public_ops)} public operations, {len(ai_ops)} AI operations, "
          f"{len(list(SCHEMAS_DIR.glob('*.json')))} schemas, {len(EXAMPLE_FILE_SCHEMAS)} example files")
    return 0


if __name__ == "__main__":
    sys.exit(main())
