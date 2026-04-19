# ──────────────────────────────────────────────────────────────────────────────
# Metrics Server (required by HPA)
# Must be installed for `kubectl top` and HPA to work on Docker Desktop / Minikube
# ──────────────────────────────────────────────────────────────────────────────
resource "helm_release" "metrics_server" {
  name             = "metrics-server"
  repository       = "https://kubernetes-sigs.github.io/metrics-server/"
  chart            = "metrics-server"
  version          = "3.12.1"
  namespace        = "kube-system"
  create_namespace = false

  # Required for Docker Desktop / Minikube (self-signed certs)
  set {
    name  = "args[0]"
    value = "--kubelet-insecure-tls"
  }

  set {
    name  = "args[1]"
    value = "--kubelet-preferred-address-types=InternalIP"
  }
}

# ──────────────────────────────────────────────────────────────────────────────
# Horizontal Pod Autoscaler per App
# Scales on CPU (70%) AND Memory (75%)
# ──────────────────────────────────────────────────────────────────────────────
resource "kubernetes_horizontal_pod_autoscaler_v2" "apps" {
  for_each = var.apps

  metadata {
    name      = "${each.value.name}-hpa"
    namespace = var.namespace
    annotations = {
      "description" = "HPA for ${each.value.name}: scales ${var.hpa_min_replicas}–${var.hpa_max_replicas} replicas based on CPU≥${var.hpa_cpu_threshold}% or Memory≥${var.hpa_memory_threshold}%"
    }
  }

  spec {
    scale_target_ref {
      api_version = "apps/v1"
      kind        = "Deployment"
      name        = kubernetes_deployment.apps[each.key].metadata[0].name
    }

    min_replicas = var.hpa_min_replicas
    max_replicas = var.hpa_max_replicas

    # ── CPU Metric ─────────────────────────────────────────────────────────────
    metric {
      type = "Resource"
      resource {
        name = "cpu"
        target {
          type                = "Utilization"
          average_utilization = var.hpa_cpu_threshold
        }
      }
    }

    # ── Memory Metric ──────────────────────────────────────────────────────────
    metric {
      type = "Resource"
      resource {
        name = "memory"
        target {
          type                = "Utilization"
          average_utilization = var.hpa_memory_threshold
        }
      }
    }

    # ── Scaling Behavior ───────────────────────────────────────────────────────
    behavior {
      # Scale UP: fast response — add up to 2 pods every 30s
      scale_up {
        stabilization_window_seconds = 30
        select_policy                = "Max"
        policy {
          type           = "Pods"
          value          = 2
          period_seconds = 30
        }
        policy {
          type           = "Percent"
          value          = 100
          period_seconds = 30
        }
      }

      # Scale DOWN: conservative — wait 5 min before reducing pods to avoid flapping
      scale_down {
        stabilization_window_seconds = 300
        select_policy                = "Min"
        policy {
          type           = "Pods"
          value          = 1
          period_seconds = 60
        }
      }
    }
  }

  depends_on = [
    kubernetes_deployment.apps,
    helm_release.metrics_server
  ]
}
