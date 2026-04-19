variable "apps" {
  type = map(object({
    name    = string
    image   = string
    domain  = string
    app_key = string
  }))
  default = {
    admin = {
      name    = "admin"
      image   = "simxstudio/admin:latest"
      domain  = "admin.simxstudio.test"
      app_key = "base64:8knLS8FCaEagmftJvlmjxvgDEWR7iOpXTHdWXcZck+w="
    }
    api = {
      name    = "api"
      image   = "simxstudio/api:latest"
      domain  = "api.simxstudio.test"
      app_key = "base64:W+ypUd5H1GyPqANEmanQ0GsW8BHH4T628FVlHNFsQLw="
    }
    leading = {
      name    = "leading"
      image   = "simxstudio/leading:latest"
      domain  = "simxstudio.test"
      app_key = "base64:wdgu544WWRqBx58aHJ2+gpQidBCtLDSur8sCSQz0Bto="
    }
  }
}

# ── Resource Sizing ────────────────────────────────────────────────────────────
variable "app_cpu_request" {
  type    = string
  default = "250m"
}

variable "app_cpu_limit" {
  type    = string
  default = "1000m"
}

variable "app_memory_request" {
  type    = string
  default = "256Mi"
}

variable "app_memory_limit" {
  type    = string
  default = "512Mi"
}

# ── HPA ───────────────────────────────────────────────────────────────────────
variable "hpa_min_replicas" {
  type    = number
  default = 1
}

variable "hpa_max_replicas" {
  type    = number
  default = 5
}

variable "hpa_cpu_threshold" {
  type    = number
  default = 70
}

variable "hpa_memory_threshold" {
  type    = number
  default = 75
}

# ── Monitoring ────────────────────────────────────────────────────────────────
variable "enable_monitoring" {
  type    = bool
  default = true
}

variable "grafana_admin_password" {
  type      = string
  default   = "simxstudio-admin"
  sensitive = true
}

variable "grafana_domain" {
  type    = string
  default = "grafana.simxstudio.test"
}

variable "prometheus_retention" {
  type    = string
  default = "7d"
}

