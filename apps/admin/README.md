# AI Photobank Admin Frontend

Nuxt 4 + Vue 3 administrative interface for AI Photobank.

## Features

- **Authentication**: Admin login with session-based auth (Laravel Sanctum)
- **Dashboard**: Overview of images, tags, processing status
- **Batch Upload**: Create batches and upload multiple images with progress tracking
- **Gallery**: Browse images with filters and view detailed information
- **Tag Management**: Manage canonical tags with tree hierarchy and aliases
- **Candidate Review**: Approve, merge, or reject AI-suggested tag candidates
- **Search**: Full-text search with tag filtering and semantic query support
- **Error Handling**: Comprehensive error handling for all API operations

## Project Structure

```
apps/admin/
├── pages/           # Route pages
├── layouts/         # Layout templates
├── components/      # Reusable Vue components
├── stores/          # Pinia stores
├── composables/     # Reusable composition functions
├── types/           # TypeScript types (from contracts)
├── middleware/      # Route middleware
└── app.vue         # Root component
```

## Development

### Setup

```bash
cd apps/admin
npm install
npm run dev
```

The app will run at `http://localhost:3000` by default.

### Environment Variables

```bash
# .env.local
NUXT_PUBLIC_API_BASE=/api
NUXT_PUBLIC_MOCK_MODE=false
```

### Scripts

- `npm run dev` - Start dev server
- `npm run build` - Build for production
- `npm run generate` - Generate static site
- `npm run preview` - Preview production build
- `npm run test` - Run unit tests
- `npm run test:e2e` - Run end-to-end tests

## API Integration

The frontend communicates exclusively with the public API defined in `contracts/public-api.openapi.yaml`.

### Key API Endpoints

- **Auth**: `/api/auth/login`, `/api/auth/logout`, `/api/auth/me`
- **Dashboard**: `/api/admin/dashboard`
- **Batches**: `/api/admin/batches`, `/api/admin/batches/{id}/images`
- **Images**: `/api/admin/images`, `/api/admin/images/{id}`
- **Tags**: `/api/admin/tags`, `/api/admin/aliases`
- **Candidates**: `/api/admin/tag-candidates`
- **Search**: `/api/admin/search`
- **Media**: `/api/media/{type}/{key}`

## Authentication

- Uses cookie-based sessions (Laravel Sanctum)
- CSRF protection via `X-XSRF-TOKEN` header
- Session check on app initialization
- Automatic redirect to login for unauthenticated requests

## Contract Compliance

The frontend is built to the specifications in `contracts/public-api.openapi.yaml`:

- Handles all documented error codes
- Respects HTTP status codes
- Processes paginated responses
- Validates form inputs before submission
- Uses correct authentication mechanisms
- Implements proper CSRF token handling

## Notes

- The frontend uses the same-origin API at `/api` by default
- All state management uses Pinia
- Components use Nuxt UI for consistent styling
- TypeScript is fully enabled for type safety
- No direct access to backend databases or file storage
