variable "aws_region" {
  description = "AWS region to deploy resources"
  type        = string
  default     = "us-east-1"
}

variable "aws_account_id" {
  description = "AWS account ID for sandbox environment (optional, only used for documentation)"
  type        = string
  default     = ""
}
