resource "kubernetes_deployment" "apps" {
  for_each = var.apps

  metadata {
    name      = each.value.name
    namespace = var.namespace
    labels = {
      app = each.value.name
    }
  }

  spec {
    replicas = 1

    selector {
      match_labels = {
        app = each.value.name
      }
    }

    template {
      metadata {
        labels = {
          app     = each.value.name
          version = "v1"
        }
        annotations = {
          "prometheus.io/scrape" = "true"
          "prometheus.io/port"   = "80"
          "prometheus.io/path"   = "/metrics"
        }
      }

      spec {
        init_container {
          name              = "migrate"
          image             = each.value.image
          image_pull_policy = "Always"
          command           = ["php", "artisan", "migrate", "--force"]

          env {
            name  = "APP_KEY"
            value = each.value.app_key
          }
          env {
            name  = "APP_ENV"
            value = "local"
          }
          env {
            name  = "DB_CONNECTION"
            value = "mysql"
          }
          env {
            name  = "DB_HOST"
            value = "mysql"
          }
          env {
            name  = "DB_PORT"
            value = var.mysql_port
          }
          env {
            name  = "DB_DATABASE"
            value = var.mysql_database
          }
          env {
            name  = "DB_USERNAME"
            value = var.mysql_user
          }
          env {
            name  = "DB_PASSWORD"
            value = var.mysql_password
          }

          resources {
            requests = {
              cpu    = "100m"
              memory = "128Mi"
            }
            limits = {
              cpu    = "500m"
              memory = "512Mi"
            }
          }
        }

        container {
          name              = "app"
          image             = each.value.image
          image_pull_policy = "Always"

          port {
            container_port = 80
            name           = "http"
          }

          port {
            container_port = 443
            name           = "https"
          }

          port {
            container_port = 443
            protocol       = "UDP"
            name           = "http3"
          }

          readiness_probe {
            http_get {
              path = "/up"
              port = 80
            }
            initial_delay_seconds = 10
            period_seconds        = 5
            failure_threshold     = 3
          }

          liveness_probe {
            http_get {
              path = "/up"
              port = 80
            }
            initial_delay_seconds = 30
            period_seconds        = 15
            failure_threshold     = 3
          }

          env {
            name  = "APP_ENV"
            value = "local"
          }
          env {
            name  = "APP_KEY"
            value = each.value.app_key
          }
          env {
            name  = "DB_CONNECTION"
            value = "mysql"
          }
          env {
            name  = "DB_HOST"
            value = "mysql"
          }
          env {
            name  = "DB_PORT"
            value = var.mysql_port
          }
          env {
            name  = "DB_DATABASE"
            value = var.mysql_database
          }
          env {
            name  = "DB_USERNAME"
            value = var.mysql_user
          }
          env {
            name  = "DB_PASSWORD"
            value = var.mysql_password
          }
          env {
            name  = "REDIS_HOST"
            value = "redis-master"
          }
          env {
            name  = "SERVER_NAME"
            value = ":80, :443"
          }
          env {
            name  = "OCTANE_SERVER"
            value = "frankenphp"
          }
          env {
            name  = "FRANKENPHP_CONFIG"
            value = ""
          }

          resources {
            requests = {
              cpu    = var.app_cpu_request
              memory = var.app_memory_request
            }
            limits = {
              cpu    = var.app_cpu_limit
              memory = var.app_memory_limit
            }
          }
        }
      }
    }
  }
}

resource "kubernetes_service" "apps" {
  for_each = var.apps

  metadata {
    name      = each.value.name
    namespace = var.namespace
  }

  spec {
    selector = {
      app = each.value.name
    }

    port {
      port        = 80
      target_port = 80
      name        = "http"
    }

    port {
      port        = 443
      target_port = 443
      name        = "https"
    }

    type = "ClusterIP"
  }
}
