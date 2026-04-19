terraform {
  required_providers {
    kubernetes = {
      source  = "hashicorp/kubernetes"
      version = "~> 2.0"
    }
    helm = {
      source  = "hashicorp/helm"
      version = "~> 2.0"
    }
  }
}

provider "kubernetes" {
  config_path    = "~/.kube/config"
  config_context = "docker-desktop"
}

provider "helm" {
  kubernetes {
    config_path    = "~/.kube/config"
    config_context = "docker-desktop"
  }
}

resource "kubernetes_namespace" "simxstudio" {
  metadata {
    name = "simxstudio"
  }
}

module "simxstudio" {
  source = "../modules/simxstudio"

  namespace = kubernetes_namespace.simxstudio.metadata[0].name
  apps      = var.apps

  # Resource sizing (used by HPA)
  app_cpu_request    = var.app_cpu_request
  app_cpu_limit      = var.app_cpu_limit
  app_memory_request = var.app_memory_request
  app_memory_limit   = var.app_memory_limit

  # HPA settings
  hpa_min_replicas     = var.hpa_min_replicas
  hpa_max_replicas     = var.hpa_max_replicas
  hpa_cpu_threshold    = var.hpa_cpu_threshold
  hpa_memory_threshold = var.hpa_memory_threshold

  # Monitoring
  enable_monitoring      = var.enable_monitoring
  grafana_admin_password = var.grafana_admin_password
  grafana_domain         = var.grafana_domain
  prometheus_retention   = var.prometheus_retention

  # Heavy Query Sampler
  heavy_query_enabled      = var.heavy_query_enabled
  heavy_query_threshold_ms = var.heavy_query_threshold_ms
  heavy_query_sample_rate  = var.heavy_query_sample_rate
}
