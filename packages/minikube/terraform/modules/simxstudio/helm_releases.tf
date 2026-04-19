resource "helm_release" "redis" {
  name       = "redis"
  chart      = "oci://registry-1.docker.io/bitnamicharts/redis"
  version    = "25.3.10" 
  namespace  = var.namespace

  set {
    name  = "architecture"
    value = "standalone"
  }

  set {
    name  = "auth.enabled"
    value = "false"
  }

  set {
    name  = "auth.allowEmptyPassword"
    value = "true"
  }

  set {
    name  = "master.persistence.enabled"
    value = "false"
  }
}
