resource "kubernetes_deployment" "mysql" {
  metadata {
    name      = "mysql"
    namespace = var.namespace
  }

  spec {
    selector {
      match_labels = {
        app = "mysql"
      }
    }

    template {
      metadata {
        labels = {
          app = "mysql"
        }
      }

      spec {
        container {
          image = "mysql:8.0"
          name  = "mysql"

          env {
            name  = "MYSQL_ROOT_PASSWORD"
            value = var.mysql_root_password
          }
          env {
            name  = "MYSQL_DATABASE"
            value = var.mysql_database
          }
          env {
            name  = "MYSQL_USER"
            value = var.mysql_user
          }
          env {
            name  = "MYSQL_PASSWORD"
            value = var.mysql_password
          }

          port {
            container_port = var.mysql_port
          }
        }
      }
    }
  }
}

resource "kubernetes_service" "mysql" {
  metadata {
    name      = "mysql"
    namespace = var.namespace
  }

  spec {
    selector = {
      app = "mysql"
    }

    port {
      port        = var.mysql_port
      target_port = var.mysql_port
    }

    type = "ClusterIP"
  }
}
