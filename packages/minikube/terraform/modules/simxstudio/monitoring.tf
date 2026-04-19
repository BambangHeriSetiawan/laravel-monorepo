# ──────────────────────────────────────────────────────────────────────────────
# Monitoring Stack: Prometheus + Grafana (kube-prometheus-stack)
#
# NOTE: PodMonitor / ServiceMonitor kubernetes_manifest resources are NOT used
# here because those CRDs are installed BY this Helm chart — creating them as
# separate Terraform resources causes a plan-time "CRD not found" error.
#
# Instead, scraping is configured via Prometheus additionalScrapeConfigs
# (annotation-based discovery) passed directly inside the Helm values.
# This is CRD-free and works on a fresh cluster with no pre-existing CRDs.
# ──────────────────────────────────────────────────────────────────────────────
resource "helm_release" "kube_prometheus_stack" {
  count = var.enable_monitoring ? 1 : 0

  name             = "kube-prometheus-stack"
  repository       = "https://prometheus-community.github.io/helm-charts"
  chart            = "kube-prometheus-stack"
  version          = "65.3.1"
  namespace        = var.namespace
  create_namespace = false

  # Use values() for the scrape config block — set() can't express YAML lists
  values = [
    yamlencode({
      prometheus = {
        prometheusSpec = {
          retention          = var.prometheus_retention
          scrapeInterval     = "15s"
          evaluationInterval = "15s"

          # Allow Prometheus to pick up ALL PodMonitors / ServiceMonitors
          # regardless of which Helm release created them
          podMonitorSelectorNilUsesHelmValues     = false
          serviceMonitorSelectorNilUsesHelmValues = false
          ruleSelectorNilUsesHelmValues           = false

          # Disable PVC — use in-memory storage for local dev
          storageSpec = {
            emptyDir = { medium = "Memory" }
          }

          # ── Annotation-based pod scraping (replaces PodMonitor CRD) ─────────
          # Scrapes any pod in any namespace that has:
          #   prometheus.io/scrape: "true"
          #   prometheus.io/port:   "80"       (optional, defaults to http)
          #   prometheus.io/path:   "/metrics" (optional)
          additionalScrapeConfigs = [
            {
              job_name              = "simxstudio-pods-annotation"
              honor_labels          = true
              kubernetes_sd_configs = [{ role = "pod" }]
              relabel_configs = [
                # Only scrape pods with annotation prometheus.io/scrape=true
                {
                  source_labels = ["__meta_kubernetes_pod_annotation_prometheus_io_scrape"]
                  action        = "keep"
                  regex         = "true"
                },
                # Filter to the simxstudio namespace only
                {
                  source_labels = ["__meta_kubernetes_namespace"]
                  action        = "keep"
                  regex         = var.namespace
                },
                # Use the prometheus.io/path annotation as the metrics path
                {
                  source_labels = ["__meta_kubernetes_pod_annotation_prometheus_io_path"]
                  action        = "replace"
                  target_label  = "__metrics_path__"
                  regex         = "(.+)"
                },
                # Use the prometheus.io/port annotation as the scrape port
                {
                  source_labels = ["__address__", "__meta_kubernetes_pod_annotation_prometheus_io_port"]
                  action        = "replace"
                  regex         = "([^:]+)(?::\\d+)?;(\\d+)"
                  replacement   = "$1:$2"
                  target_label  = "__address__"
                },
                # Add namespace label
                {
                  action       = "labelmap"
                  regex        = "__meta_kubernetes_pod_label_(.+)"
                },
                {
                  source_labels = ["__meta_kubernetes_namespace"]
                  action        = "replace"
                  target_label  = "kubernetes_namespace"
                },
                # Add pod name label
                {
                  source_labels = ["__meta_kubernetes_pod_name"]
                  action        = "replace"
                  target_label  = "kubernetes_pod_name"
                },
              ]
            },
            # ── Redis scraping (standalone mode exposes metrics on port 9121) ──
            {
              job_name              = "simxstudio-redis"
              kubernetes_sd_configs = [{ role = "endpoints" }]
              relabel_configs = [
                {
                  source_labels = ["__meta_kubernetes_namespace", "__meta_kubernetes_service_name"]
                  separator     = "/"
                  action        = "keep"
                  regex         = "${var.namespace}/redis-master"
                },
              ]
            },
          ]
        }
      }

      grafana = {
        enabled                  = true
        adminPassword            = var.grafana_admin_password
        defaultDashboardsEnabled = true
        defaultDashboardsTimezone = "Asia/Jakarta"
        persistence              = { enabled = false }

        sidecar = {
          dashboards = {
            enabled         = true
            label           = "grafana_dashboard"
            searchNamespace = "ALL"
          }
          datasources = { enabled = true }
        }

        ingress = {
          enabled           = true
          ingressClassName  = "nginx"
          hosts             = [var.grafana_domain]
          path              = "/"
          pathType          = "Prefix"
        }
      }

      alertmanager = {
        enabled = true
        alertmanagerSpec = {
          storage = {
            volumeClaimTemplate = {
              spec = {
                resources = { requests = { storage = "1Gi" } }
              }
            }
          }
        }
      }

      nodeExporter    = { enabled = true }
      kubeStateMetrics = { enabled = true }
    })
  ]
}

# ──────────────────────────────────────────────────────────────────────────────
# Grafana Dashboard ConfigMaps
# Grafana sidecar auto-imports any ConfigMap with label grafana_dashboard=1
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
