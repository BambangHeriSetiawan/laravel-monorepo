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
