# Compiled "protected core" service: ships as a static binary without sources.
FROM golang:1.23-alpine AS build

WORKDIR /src

# Dependencies are pinned in go.sum; pgx is the only non-stdlib dependency.
COPY go.mod go.sum ./
RUN go mod download

COPY main.go ./
COPY internal ./internal

# Vendor Ed25519 public key, pinned into the binary so the trust root is not an
# environment variable an operator could replace. Public data: it is safe to keep
# in the image history. Leave empty only for development builds.
ARG LICENSE_PUBLIC_KEY_B64=""

# Static build, no cgo: the result runs on a source-less base image.
RUN CGO_ENABLED=0 GOOS=linux go build -trimpath \
    -ldflags="-s -w -X main.defaultLicensePublicKey=${LICENSE_PUBLIC_KEY_B64}" \
    -o /out/core .

# Tests run in the build stage so a broken algorithm cannot be shipped.
RUN go vet ./... && go test ./...

FROM alpine:3.20

RUN adduser -D -H -u 10001 core

COPY --from=build /out/core /usr/local/bin/core

# License/module state lives here (compose mounts `core_data`). The directory must
# be owned by the service user, otherwise the first start fails with
# "mkdir /var/lib/core: permission denied".
RUN mkdir -p /var/lib/core && chown -R core:core /var/lib/core

USER core
EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/core"]
