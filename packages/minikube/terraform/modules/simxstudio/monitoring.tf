# ──────────────────────────────────────────────────────────────────────────────
# Monitoring Stack: Prometheus + Grafana (kube-prometheus-stack)
# ──────────────────────────────────────────────────────────────────────────────
resource "helm_release" "kube_prometheus_stack" {
  count = var.enable_monitoring ? 1 : 0

  name             = "kube-prometheus-stack"
  repository       = "https://prometheus-community.github.io/helm-charts"
  chart            = "kube-prometheus-stack"
  version          = "65.3.1"
  namespace        = var.namespace
  create_namespace = false

  # ── Prometheus ──────────────────────────────────────────────────────────────
  set {
    name  = "prometheus.prometheusSpec.retention"
    value = var.prometheus_retention
  }

  set {
    name  = "prometheus.prometheusSpec.scrapeInterval"
    value = "15s"
  }

  set {
    name  = "prometheus.prometheusSpec.evaluationInterval"
    value = "15s"
  }

  # Discover pods with prometheus.io/scrape=true annotations
  set {
    name  = "prometheus.prometheusSpec.podMonitorSelectorNilUsesHelmValues"
    value = "false"
  }

  set {
    name  = "prometheus.prometheusSpec.serviceMonitorSelectorNilUsesHelmValues"
    value = "false"
  }

  # Storage for Prometheus (disable PVC for local dev)
  set {
    name  = "prometheus.prometheusSpec.storageSpec.emptyDir.medium"
    value = "Memory"
  }

  # ── Grafana ─────────────────────────────────────────────────────────────────
  set {
    name  = "grafana.enabled"
    value = "true"
  }

  set {
    name  = "grafana.adminPassword"
    value = var.grafana_admin_password
  }

  set {
    name  = "grafana.defaultDashboardsEnabled"
    value = "true"
  }

  set {
    name  = "grafana.defaultDashboardsTimezone"
    value = "Asia/Jakarta"
  }

  # Grafana persistence (disable for local dev)
  set {
    name  = "grafana.persistence.enabled"
    value = "false"
  }

  # Pre-load dashboards from ConfigMaps
  set {
    name  = "grafana.sidecar.dashboards.enabled"
    value = "true"
  }

  set {
    name  = "grafana.sidecar.dashboards.label"
    value = "grafana_dashboard"
  }

  set {
    name  = "grafana.sidecar.dashboards.searchNamespace"
    value = "ALL"
  }

  # Grafana datasources
  set {
    name  = "grafana.sidecar.datasources.enabled"
    value = "true"
  }

  # ── AlertManager ────────────────────────────────────────────────────────────
  set {
    name  = "alertmanager.enabled"
    value = "true"
  }

  set {
    name  = "alertmanager.alertmanagerSpec.storage.volumeClaimTemplate.spec.resources.requests.storage"
    value = "1Gi"
  }

  # ── Node Exporter (host metrics) ────────────────────────────────────────────
  set {
    name  = "nodeExporter.enabled"
    value = "true"
  }

  # ── kube-state-metrics (K8s object metrics) ─────────────────────────────────
  set {
    name  = "kubeStateMetrics.enabled"
    value = "true"
  }

  # ── Ingress for Grafana ──────────────────────────────────────────────────────
  set {
    name  = "grafana.ingress.enabled"
    value = "true"
  }

  set {
    name  = "grafana.ingress.ingressClassName"
    value = "nginx"
  }

  set {
    name  = "grafana.ingress.hosts[0]"
    value = var.grafana_domain
  }

  set {
    name  = "grafana.ingress.path"
    value = "/"
  }

  set {
    name  = "grafana.ingress.pathType"
    value = "Prefix"
  }
}

# ──────────────────────────────────────────────────────────────────────────────
# PodMonitor: scrape all SimXStudio app pods
# ──────────────────────────────────────────────────────────────────────────────
resource "kubernetes_manifest" "app_pod_monitor" {
  count = var.enable_monitoring ? 1 : 0

  manifest = {
    apiVersion = "monitoring.coreos.com/v1"
    kind       = "PodMonitor"
    metadata = {
      name      = "simxstudio-apps"
      namespace = var.namespace
      labels = {
        release = "kube-prometheus-stack"
      }
    }
    spec = {
      selector = {
        matchExpressions = [
          {
            key      = "app"
            operator = "In"
            values   = [for k, v in var.apps : v.name]
          }
        ]
      }
      podMetricsEndpoints = [
        {
          port = "http"
          path = "/metrics"
          interval = "15s"
        }
      ]
    }
  }

  depends_on = [helm_release.kube_prometheus_stack]
}

# ──────────────────────────────────────────────────────────────────────────────
# ServiceMonitor: scrape Redis
# ──────────────────────────────────────────────────────────────────────────────
resource "kubernetes_manifest" "redis_service_monitor" {
  count = var.enable_monitoring ? 1 : 0

  manifest = {
    apiVersion = "monitoring.coreos.com/v1"
    kind       = "ServiceMonitor"
    metadata = {
      name      = "redis-monitor"
      namespace = var.namespace
      labels = {
        release = "kube-prometheus-stack"
      }
    }
    spec = {
      selector = {
        matchLabels = {
          "app.kubernetes.io/name" = "redis"
        }
      }
      endpoints = [
        {
          port = "metrics"
          interval = "30s"
        }
      ]
    }
  }

  depends_on = [helm_release.kube_prometheus_stack, helm_release.redis]
}

# ──────────────────────────────────────────────────────────────────────────────
# Grafana Dashboard ConfigMaps
# ──────────────────────────────────────────────────────────────────────────────
resource "kubernetes_config_map" "grafana_laravel_dashboard" {
  count = var.enable_monitoring ? 1 : 0

  metadata {
    name      = "grafana-laravel-dashboard"
    namespace = var.namespace
    labels = {
      grafana_dashboard = "1"
    }
  }

  data = {
    "laravel-overview.json" = file("${path.module}/grafana-dashboards/laravel-overview.json")
  }

  depends_on = [helm_release.kube_prometheus_stack]
}

resource "kubernetes_config_map" "grafana_hpa_dashboard" {
  count = var.enable_monitoring ? 1 : 0

  metadata {
    name      = "grafana-hpa-dashboard"
    namespace = var.namespace
    labels = {
      grafana_dashboard = "1"
    }
  }

  data = {
    "hpa-overview.json" = file("${path.module}/grafana-dashboards/hpa-overview.json")
  }

  depends_on = [helm_release.kube_prometheus_stack]
}
