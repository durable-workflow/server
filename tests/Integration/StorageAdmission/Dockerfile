ARG SERVER_IMAGE
FROM ${SERVER_IMAGE}

COPY --chown=1000:1000 app /app/app
COPY --chown=1000:1000 config /app/config
COPY --chown=1000:1000 routes /app/routes
COPY --chown=1000:1000 resources/platform-protocol-specs /app/resources/platform-protocol-specs
USER 1000:1000
RUN php artisan route:clear
