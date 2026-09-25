# syntax=docker/dockerfile:1

FROM node:22-alpine AS build
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM node:22-alpine AS runtime
RUN apk add --no-cache curl \
    && addgroup -S nuxt && adduser -S nuxt -G nuxt
WORKDIR /app
ENV NODE_ENV=production \
    HOST=0.0.0.0 \
    PORT=3000
COPY --from=build --chown=nuxt:nuxt /app/.output ./.output
USER nuxt
EXPOSE 3000
CMD ["node", ".output/server/index.mjs"]
