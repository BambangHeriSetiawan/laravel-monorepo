# Simxstudio Monorepo

A modern Laravel monorepo structure utilizing **FrankenPHP**, **Laravel Octane**, **Terraform**, and **Docker Desktop Kubernetes**.

## 🏗️ Project Structure

```text
.
├── app
│   ├── admin          # Admin Portal (Livewire/Flux/Octane)
│   ├── api            # Backend API (Laravel/Octane)
│   └── leading        # Marketing/Landing Page (Laravel/Octane)
├── packages
│   ├── shared-core    # Shared Laravel package used by all apps
│   └── minikube       # Infrastructure (Terraform) - Now set for Docker Desktop
└── build.sh           # Unified Docker build script
```

## 🚀 Local Development

### 1. Prerequisites
- PHP 8.4+
- Composer
- Node.js & NPM
- Docker Desktop (with Kubernetes enabled)

### 2. Setup
Run the following from the root to install shared dependencies:
```bash
composer install
```

For each app in `app/*`, ensure Octane is installed:
```bash
cd app/admin
composer install
php artisan octane:install --server=frankenphp
npm install && npm run build
```

## 🐳 Containerization

Applications use **multi-stage Dockerfiles** optimized for **FrankenPHP + Octane**.

### Building Images
The `build.sh` script automatically builds all images and makes them available to the Docker Desktop Kubernetes cluster:

```bash
./build.sh
```

> [!NOTE]
> A `.dockerignore` file is present in the root to ensure local `vendor` and `node_modules` do not conflict with the container builds.

## ☸️ Infrastructure (Docker Desktop K8s)

We use Terraform to manage local Kubernetes resources.

### Prerequisites
1. **Enable Kubernetes**: In Docker Desktop Settings -> Kubernetes -> Check "Enable Kubernetes".
2. **Disable Minikube**: `minikube stop` (to save system resources).
3. **Install Ingress Controller**:
   ```bash
   helm upgrade --install ingress-nginx ingress-nginx \
     --repo https://kubernetes.github.io/ingress-nginx \
     --namespace ingress-nginx --create-namespace
   ```

### Deploying
```bash
cd packages/minikube/terraform/minikube
terraform init
terraform apply
```

### 🌐 Domain Configuration (Hosts File)
Add the following to your `/etc/hosts` file to map the local cluster to your custom domains:

```text
127.0.0.1 admin.simxstudio.test
127.0.0.1 api.simxstudio.test
127.0.0.1 simxstudio.test
```

## 🛠️ Tech Stack
- **Server**: [FrankenPHP](https://frankenphp.dev/) via [Laravel Octane](https://laravel.com/docs/octane)
- **Backend**: Laravel 13.x
- **Frontend**: Livewire, Flux UI, Vite
- **Infrastructure**: Terraform, Kubernetes, Helm (MySQL, Redis)

---

## 🐛 Troubleshooting

A log of real issues encountered during setup and their resolutions.

---

### ❌ `worker public/frankenphp-worker.php has not reached frankenphp_handle_request()`

**Symptom**: Pods crash on startup with this FrankenPHP error.

**Root cause**: `php artisan optimize` was being run during `docker build` with no env vars set, caching empty config values. At runtime, the injected `APP_KEY` was ignored, causing Laravel to crash silently.

**Fix**: Remove `php artisan optimize` from the Dockerfile. Only run `composer dump-autoload` and `octane:install` at build time. Let Laravel cache config at runtime.

---

### ❌ `503 Service Temporarily Unavailable` (nginx)

**Symptom**: All domains return 503 after deployment.

**Root cause 1 — Pods crashing**: Ingress is healthy but has zero healthy backends because the `readiness_probe` was hitting `GET /` which returned `500` (database not migrated yet).

**Fix**: Changed the readiness probe path to `GET /up`, Laravel's built-in health endpoint that does not touch the database.

**Root cause 2 — Ingress class not set**: The Ingress resource showed `Class: <none>`, meaning the Nginx Ingress Controller was not picking it up.

**Fix**: Added `ingress_class_name = "nginx"` to the Ingress `spec` block in `ingress.tf`.

---

### ❌ `500` errors on all routes after pods become `Running`

**Symptom**: Apps start, but every request returns HTTP 500.

**Root cause**: Database tables did not exist (no migrations run).

**Fix (manual)**: Exec into the running pod and run migrations:
```bash
kubectl exec -it deployment/api -n simxstudio -- php artisan migrate --force
```

**Fix (automated)**: An `init_container` named `migrate` is now defined in `apps.tf`. It automatically runs `php artisan migrate --force` before the main app container starts on every deployment.

---

### ❌ `deployments.apps "X" already exists` / `services "X" already exists`

**Symptom**: `terraform apply` fails because resources already exist in the cluster.

**Root cause**: Terraform's local state file was deleted or out of sync with the actual cluster state.

**Fix**: Import all existing resources into Terraform state using the provided helper script:
```bash
cd packages/minikube/terraform/minikube
bash ../import.sh
terraform apply
```

---

### ❌ `cannot replace to directory /var/lib/docker/... with file`

**Symptom**: `docker build` fails when running `COPY app/admin .` over an existing vendor directory.

**Root cause**: Local `vendor` folder was being sent to the Docker daemon context and conflicting with the multi-stage build.

**Fix**: A `.dockerignore` file at the project root excludes `**/vendor` and `**/node_modules` from the build context. Also, application code is now copied **before** the builder-stage vendor is overlaid.

---

### ❌ `Error: Unsupported block type "kubernetes"` in helm provider

**Symptom**: `terraform plan` fails on the `provider "helm"` block.

**Root cause**: The `helm` provider was not declared in the `required_providers` block.

**Fix**: Add `helm` to `required_providers` in `main.tf` and run `terraform init -upgrade`.
```hcl
helm = {
  source  = "hashicorp/helm"
  version = "~> 2.0"
}
```

---

### ❌ `locked provider ... does not match configured version constraint`

**Symptom**: `terraform init` fails due to version mismatch.

**Fix**: Delete the lock file and reinitialize:
```bash
rm .terraform.lock.hcl
terraform init -upgrade
```
