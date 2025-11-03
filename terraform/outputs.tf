output "valkey_endpoint" {
  description = "Valkey Serverless endpoint"
  value       = aws_elasticache_serverless_cache.valkey.endpoint[0].address
}

output "valkey_port" {
  description = "Valkey Serverless port"
  value       = "6379"
}

output "ecr_repository_url" {
  description = "ECR repository URL"
  value       = aws_ecr_repository.test.repository_url
}

output "ecs_cluster_name" {
  description = "ECS cluster name"
  value       = aws_ecs_cluster.main.name
}

output "ecs_task_definition" {
  description = "ECS task definition family"
  value       = aws_ecs_task_definition.test.family
}

output "docker_build_commands" {
  description = "Commands to build and push Docker image"
  value = <<-EOT
    # Build and push Docker image
    cd fargate
    aws ecr get-login-password --region ${var.aws_region} | docker login --username AWS --password-stdin ${aws_ecr_repository.test.repository_url}
    docker build -t ${aws_ecr_repository.test.repository_url}:latest .
    docker push ${aws_ecr_repository.test.repository_url}:latest
  EOT
}

output "run_task_command" {
  description = "Command to run the Fargate task"
  value = <<-EOT
    aws ecs run-task \
      --cluster ${aws_ecs_cluster.main.name} \
      --task-definition ${aws_ecs_task_definition.test.family} \
      --launch-type FARGATE \
      --network-configuration "awsvpcConfiguration={subnets=[${aws_subnet.private_a.id},${aws_subnet.private_b.id}],securityGroups=[${aws_security_group.fargate.id}]}" \
      --region ${var.aws_region}
  EOT
}

output "logs_command" {
  description = "Command to view CloudWatch logs"
  value       = "aws logs tail ${aws_cloudwatch_log_group.fargate.name} --follow --region ${var.aws_region}"
}
