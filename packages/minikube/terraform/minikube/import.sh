#!/bin/bash
# Run this script once to import existing Kubernetes resources into Terraform state.
# Usage: cd packages/minikube/terraform/minikube && bash ../import.sh

set -e

echo "📦 Importing Kubernetes resources into Terraform state..."

terraform import kubernetes_namespace.simxstudio simxstudio || true

terraform import 'module.simxstudio.kubernetes_deployment.apps["admin"]' simxstudio/admin || true
terraform import 'module.simxstudio.kubernetes_deployment.apps["api"]' simxstudio/api || true
terraform import 'module.simxstudio.kubernetes_deployment.apps["leading"]' simxstudio/leading || true
terraform import module.simxstudio.kubernetes_deployment.mysql simxstudio/mysql || true

terraform import 'module.simxstudio.kubernetes_service.apps["admin"]' simxstudio/admin || true
terraform import 'module.simxstudio.kubernetes_service.apps["api"]' simxstudio/api || true
terraform import 'module.simxstudio.kubernetes_service.apps["leading"]' simxstudio/leading || true
terraform import module.simxstudio.kubernetes_service.mysql simxstudio/mysql || true

terraform import module.simxstudio.helm_release.redis simxstudio/redis || true
terraform import module.simxstudio.kubernetes_ingress_v1.ingress simxstudio/simxstudio-ingress || true

echo "✅ Import complete! Now run: terraform apply"
