variable "namespace" {
  type = string
}

variable "apps" {
  type = map(object({
    name    = string
    image   = string
    domain  = string
    app_key = string
  }))
}

variable "mysql_root_password" {
  type    = string
  default = "root"
}

variable "mysql_database" {
  type    = string
  default = "simxstudio"
}

variable "mysql_user" {
  type    = string
  default = "admin"
}

variable "mysql_password" {
  type    = string
  default = "secret"
}

variable "mysql_port" {
  type    = number
  default = 3306
}

# ──────────────────────────────────────────────
# App Resource Sizing (required for HPA)
# ──────────────────────────────────────────────
variable "app_cpu_request" {
  type        = string
  default     = "250m"
  description = "CPU request per app pod. HPA uses this as the baseline for scaling decisions."
}

variable "app_cpu_limit" {
  type        = string
  default     = "1000m"
  description = "CPU limit per app pod (1 vCPU)."
}

variable "app_memory_request" {
  type        = string
  default     = "256Mi"
  description = "Memory request per app pod."
}

variable "app_memory_limit" {
  type        = string
  default     = "512Mi"
  description = "Memory limit per app pod."
}

# ──────────────────────────────────────────────
# Autoscaling (HPA)
# ──────────────────────────────────────────────
variable "hpa_min_replicas" {
  type        = number
  default     = 1
  description = "Minimum number of pod replicas per app."
}

variable "hpa_max_replicas" {
  type        = number
  default     = 5
  description = "Maximum number of pod replicas per app."
}

variable "hpa_cpu_threshold" {
  type        = number
  default     = 70
  description = "CPU utilization percentage that triggers scale-out."
}

variable "hpa_memory_threshold" {
  type        = number
  default     = 75
  description = "Memory utilization percentage that triggers scale-out."
}

# ──────────────────────────────────────────────
# Grafana Monitoring
# ──────────────────────────────────────────────
variable "enable_monitoring" {
  type        = bool
  default     = true
  description = "Deploy Prometheus + Grafana monitoring stack."
}

variable "grafana_admin_password" {
  type        = string
  default     = "simxstudio-admin"
  description = "Grafana admin password."
  sensitive   = true
}

variable "grafana_domain" {
  type        = string
  default     = "monitoring.simxstudio.test"
  description = "Domain for Grafana ingress."
}

variable "prometheus_retention" {
  type        = string
  default     = "7d"
  description = "Prometheus data retention period."
}

# ──────────────────────────────────────────────
# Heavy Query Sampler
# ──────────────────────────────────────────────
variable "heavy_query_enabled" {
  type        = string
  default     = "true"
  description = "Enable/disable the heavy query sampler in deployed pods."
}

variable "heavy_query_threshold_ms" {
  type        = string
  default     = "500"
  description = "Slow query threshold in milliseconds for Kubernetes pods."
}

variable "heavy_query_sample_rate" {
  type        = string
  default     = "0.25"
  description = "Fraction of heavy queries to capture (0.0–1.0). 0.25 = 25% under load."
}


