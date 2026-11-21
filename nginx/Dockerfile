# Distribution image: serves the PREBUILT SPA (frontend/dist) with no Node and
# no frontend sources. This file is the template for the customer distribution;
# `scripts/package_distribution.ps1` writes it into the bundle as nginx/Dockerfile,
# so the bundle's docker-compose.prod.yml stays byte-identical to the repo.
FROM nginx:alpine

RUN mkdir -p /etc/nginx/snippets

COPY nginx/prod/default.conf /etc/nginx/conf.d/default.conf
COPY nginx/prod/laravel.conf /etc/nginx/snippets/laravel.conf
COPY frontend/dist /usr/share/nginx/html

EXPOSE 80
