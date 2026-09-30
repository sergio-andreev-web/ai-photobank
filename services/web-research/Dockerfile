FROM node:22-bookworm-slim
WORKDIR /app/services/web-research
COPY services/web-research/package*.json ./
RUN npm ci --omit=dev && npx playwright install --with-deps chromium
COPY services/web-research/src ./src
COPY contracts/schemas /app/contracts/schemas
ENV WEB_RESEARCH_HOST=0.0.0.0 WEB_RESEARCH_PORT=8002
USER node
EXPOSE 8002
CMD ["node", "src/server.mjs"]
