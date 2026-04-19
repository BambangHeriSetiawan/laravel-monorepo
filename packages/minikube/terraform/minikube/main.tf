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
}
