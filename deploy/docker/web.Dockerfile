# Keelwatch web: the built dashboard served by Caddy, which also terminates
# TLS (automatic certificates) and forwards API routes to the api service.
FROM node:24-alpine AS build
WORKDIR /web
COPY web/package.json web/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY web/ ./
RUN npm run build

FROM caddy:2-alpine
COPY deploy/docker/Caddyfile /etc/caddy/Caddyfile
COPY --from=build /web/dist /srv
