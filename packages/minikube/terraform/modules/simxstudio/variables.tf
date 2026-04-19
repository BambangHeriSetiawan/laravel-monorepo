variable "namespace" {
  type = string
}

variable "apps" {
  type = map(object({
    name    = string
    image   = string
    domain  = string
    app_key = string
  }))
}

variable "mysql_root_password" {
  type    = string
  default = "root"
}

variable "mysql_database" {
  type    = string
  default = "simxstudio"
}

variable "mysql_user" {
  type    = string
  default = "admin"
}

variable "mysql_password" {
  type    = string
  default = "secret"
}

variable "mysql_port" {
  type    = number
  default = 3306
}
