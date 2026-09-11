# syntax=docker/dockerfile:1

# Next 16 exige Node >= 20.9 (engines do pacote). 22 é o LTS atual.
FROM node:22-alpine AS deps
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci

# --- dev: alvo usado pelo docker-compose ---
FROM deps AS dev
ENV NEXT_TELEMETRY_DISABLED=1
# Bind mount sobre WSL2/virtiofs perde eventos de inotify; sem polling o HMR
# simplesmente não reage a algumas edições.
ENV WATCHPACK_POLLING=true
COPY . .
EXPOSE 3000
CMD ["npm", "run", "dev", "--", "--hostname", "0.0.0.0", "--port", "3000"]

# --- build: gera o bundle standalone ---
FROM deps AS build
ENV NEXT_TELEMETRY_DISABLED=1
COPY . .
RUN npm run build

# --- runner: imagem de produção, só o standalone ---
FROM node:22-alpine AS runner
WORKDIR /app
ENV NODE_ENV=production
ENV NEXT_TELEMETRY_DISABLED=1
ENV PORT=3000
ENV HOSTNAME=0.0.0.0

RUN addgroup -g 1001 -S nodejs && adduser -S nextjs -u 1001

COPY --from=build /app/public ./public
COPY --from=build --chown=nextjs:nodejs /app/.next/standalone ./
COPY --from=build --chown=nextjs:nodejs /app/.next/static ./.next/static

USER nextjs
EXPOSE 3000
CMD ["node", "server.js"]
