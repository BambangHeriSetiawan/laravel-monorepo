# Terraform for Minikube

This directory follows a modularized structure synchronized with your reference project.

## Structure

```text
.
├── minikube/           # Minikube environment setup
│   ├── main.tf        # Entry point, calls simxstudio module
│   └── variables.tf   # App and domain configuration
└── modules/
    └── simxstudio/    # Shared logic for apps, DB, and Redis
        ├── apps.tf
        ├── mysql.tf
        ├── helm_releases.tf
        ├── ingress.tf
        └── variables.tf
```

## Prerequisites (Docker Desktop)

1.  **Enable Kubernetes** in Docker Desktop Settings.
2.  **Disable Minikube**: `minikube stop`
3.  **Install Nginx Ingress Controller** (Required for Docker Desktop):
    ```bash
    helm upgrade --install ingress-nginx ingress-nginx \
      --repo https://kubernetes.github.io/ingress-nginx \
      --namespace ingress-nginx --create-namespace
    ```
4.  **Hosts File**: On Docker Desktop, the IP is always `127.0.0.1`.
    ```text
    127.0.0.1 admin.simxstudio.test
    127.0.0.1 api.simxstudio.test
    127.0.0.1 simxstudio.test
    ```

## Usage

1.  Navigate to the environment directory:
    ```bash
    cd packages/minikube/terraform/minikube
    ```
2.  Initialize:
    ```bash
    terraform init
    ```
3.  Deploy:
    ```bash
    terraform apply
    ```
