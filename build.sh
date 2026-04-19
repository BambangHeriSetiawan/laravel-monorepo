#!/bin/bash

# Exit immediately if a command exits with a non-zero status
set -e

# Default tag
TAG=${1:-latest}

echo "🚀 Starting builds for Simxstudio Monorepo (Tag: $TAG)..."
echo "🐳 Using Docker Desktop daemon..."

# Build Admin
echo "📦 Building Admin..."
docker build  --no-cache -f app/admin/Dockerfile -t simxstudio/admin:$TAG .

# Build API
echo "📦 Building API..."
docker build  --no-cache -f app/api/Dockerfile -t simxstudio/api:$TAG .

# Build Leading
echo "📦 Building Leading..."
docker build  --no-cache -f app/leading/Dockerfile -t simxstudio/leading:$TAG .

echo "✅ All builds completed successfully!"
echo "-------------------------------------"
echo "simxstudio/admin:$TAG"
echo "simxstudio/api:$TAG"
echo "simxstudio/leading:$TAG"
