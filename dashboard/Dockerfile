#syntax=docker/dockerfile:1

# The Atelier as a static bundle, for deployments that do not run the Vite dev server (the offline demo,
# compose.demo.yaml). The VITE_* values are baked in at build time: the issuer is where the browser
# sends the login, the client id is the Atelier's PKCE application on it.
FROM node:22-alpine AS build

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .

ARG VITE_CASDOOR_ISSUER=https://auth.bonneref.fr
ARG VITE_CASDOOR_CLIENT_ID=671b8c86d75444f3f4c4
ARG VITE_MERCURE_URL=/.well-known/mercure
# Empty = same-origin. It must be set: .env's dev https://localhost would otherwise be baked in.
ARG VITE_API_BASE=
ENV VITE_API_BASE=${VITE_API_BASE} \
	VITE_CASDOOR_ISSUER=${VITE_CASDOOR_ISSUER} \
	VITE_CASDOOR_CLIENT_ID=${VITE_CASDOOR_CLIENT_ID} \
	VITE_MERCURE_URL=${VITE_MERCURE_URL}
RUN npm run build

FROM caddy:2.11.4 AS static

COPY --from=build /app/dist /srv
COPY <<EOF /etc/caddy/Caddyfile
:80 {
	root * /srv
	header /assets/* Cache-Control "public, max-age=31536000, immutable"
	try_files {path} /index.html
	file_server
}
EOF
