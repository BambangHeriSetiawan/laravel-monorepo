resource "kubernetes_ingress_v1" "ingress" {
  metadata {
    name      = "simxstudio-ingress"
    namespace = var.namespace
    annotations = {
      "kubernetes.io/ingress.class"           = "nginx"
      "nginx.ingress.kubernetes.io/use-regex" = "true"
    }
  }

  spec {
    ingress_class_name = "nginx"

    dynamic "rule" {
      for_each = var.apps
      content {
        host = rule.value.domain
        http {
          path {
            path      = "/"
            path_type = "Prefix"
            backend {
              service {
                name = kubernetes_service.apps[rule.key].metadata[0].name
                port {
                  number = 80
                }
              }
            }
          }
        }
      }
    }
  }
}
