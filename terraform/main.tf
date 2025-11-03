terraform {
  required_version = ">= 1.0"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.0"
    }
  }
}

provider "aws" {
  region = var.aws_region

  default_tags {
    tags = {
      Project     = "redis-cluster-test"
      Environment = "sandbox"
      ManagedBy   = "terraform"
    }
  }
}

# VPC and subnets
resource "aws_vpc" "main" {
  cidr_block           = "10.1.0.0/16"
  enable_dns_hostnames = true
  enable_dns_support   = true

  tags = {
    Name = "redis-cluster-test-vpc"
  }
}

resource "aws_subnet" "private_a" {
  vpc_id            = aws_vpc.main.id
  cidr_block        = "10.1.1.0/24"
  availability_zone = "${var.aws_region}a"

  tags = {
    Name = "redis-cluster-test-private-a"
  }
}

resource "aws_subnet" "private_b" {
  vpc_id            = aws_vpc.main.id
  cidr_block        = "10.1.2.0/24"
  availability_zone = "${var.aws_region}c"

  tags = {
    Name = "redis-cluster-test-private-b"
  }
}

# Security group for VPC endpoints
resource "aws_security_group" "vpc_endpoints" {
  name        = "redis-cluster-test-vpc-endpoints"
  description = "Security group for VPC endpoints"
  vpc_id      = aws_vpc.main.id

  ingress {
    description = "HTTPS from VPC"
    from_port   = 443
    to_port     = 443
    protocol    = "tcp"
    cidr_blocks = [aws_vpc.main.cidr_block]
  }

  egress {
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = {
    Name = "redis-cluster-test-vpc-endpoints"
  }
}

# VPC endpoint - ECR API
resource "aws_vpc_endpoint" "ecr_api" {
  vpc_id              = aws_vpc.main.id
  service_name        = "com.amazonaws.${var.aws_region}.ecr.api"
  vpc_endpoint_type   = "Interface"
  subnet_ids          = [aws_subnet.private_a.id, aws_subnet.private_b.id]
  security_group_ids  = [aws_security_group.vpc_endpoints.id]
  private_dns_enabled = true

  tags = {
    Name = "redis-cluster-test-ecr-api"
  }
}

# VPC endpoint - ECR DKR
resource "aws_vpc_endpoint" "ecr_dkr" {
  vpc_id              = aws_vpc.main.id
  service_name        = "com.amazonaws.${var.aws_region}.ecr.dkr"
  vpc_endpoint_type   = "Interface"
  subnet_ids          = [aws_subnet.private_a.id, aws_subnet.private_b.id]
  security_group_ids  = [aws_security_group.vpc_endpoints.id]
  private_dns_enabled = true

  tags = {
    Name = "redis-cluster-test-ecr-dkr"
  }
}

# VPC endpoint - S3 (for ECR layer storage)
resource "aws_vpc_endpoint" "s3" {
  vpc_id            = aws_vpc.main.id
  service_name      = "com.amazonaws.${var.aws_region}.s3"
  vpc_endpoint_type = "Gateway"
  route_table_ids   = [aws_vpc.main.default_route_table_id]

  tags = {
    Name = "redis-cluster-test-s3"
  }
}

# VPC endpoint - CloudWatch Logs
resource "aws_vpc_endpoint" "logs" {
  vpc_id              = aws_vpc.main.id
  service_name        = "com.amazonaws.${var.aws_region}.logs"
  vpc_endpoint_type   = "Interface"
  subnet_ids          = [aws_subnet.private_a.id, aws_subnet.private_b.id]
  security_group_ids  = [aws_security_group.vpc_endpoints.id]
  private_dns_enabled = true

  tags = {
    Name = "redis-cluster-test-logs"
  }
}

# Subnet group for Valkey Serverless
resource "aws_elasticache_subnet_group" "valkey" {
  name       = "redis-cluster-test-valkey-subnet"
  subnet_ids = [aws_subnet.private_a.id, aws_subnet.private_b.id]

  tags = {
    Name = "redis-cluster-test-valkey-subnet"
  }
}

# Security groups
resource "aws_security_group" "valkey" {
  name        = "redis-cluster-test-valkey"
  description = "Security group for Valkey Serverless"
  vpc_id      = aws_vpc.main.id

  ingress {
    description     = "Valkey from Fargate"
    from_port       = 6379
    to_port         = 6379
    protocol        = "tcp"
    security_groups = [aws_security_group.fargate.id]
  }

  egress {
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = {
    Name = "redis-cluster-test-valkey"
  }
}

resource "aws_security_group" "fargate" {
  name        = "redis-cluster-test-fargate"
  description = "Security group for Fargate task"
  vpc_id      = aws_vpc.main.id

  egress {
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = {
    Name = "redis-cluster-test-fargate"
  }
}

# Valkey Serverless cluster
resource "aws_elasticache_serverless_cache" "valkey" {
  engine = "valkey"
  name   = "redis-cluster-test"

  cache_usage_limits {
    data_storage {
      maximum = 1
      unit    = "GB"
    }
    ecpu_per_second {
      maximum = 1000
    }
  }

  security_group_ids = [aws_security_group.valkey.id]
  subnet_ids         = [aws_subnet.private_a.id, aws_subnet.private_b.id]

  tags = {
    Name = "redis-cluster-test-valkey"
  }
}

# ECR repository
resource "aws_ecr_repository" "test" {
  name                 = "redis-cluster-test"
  image_tag_mutability = "MUTABLE"

  image_scanning_configuration {
    scan_on_push = false
  }

  tags = {
    Name = "redis-cluster-test"
  }
}

# ECS cluster
resource "aws_ecs_cluster" "main" {
  name = "redis-cluster-test"

  tags = {
    Name = "redis-cluster-test"
  }
}

# CloudWatch log group
resource "aws_cloudwatch_log_group" "fargate" {
  name              = "/ecs/redis-cluster-test"
  retention_in_days = 7
}

# ECS task execution role
resource "aws_iam_role" "ecs_task_execution" {
  name = "redis-cluster-test-ecs-task-execution"

  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Action = "sts:AssumeRole"
        Effect = "Allow"
        Principal = {
          Service = "ecs-tasks.amazonaws.com"
        }
      }
    ]
  })
}

resource "aws_iam_role_policy_attachment" "ecs_task_execution" {
  role       = aws_iam_role.ecs_task_execution.name
  policy_arn = "arn:aws:iam::aws:policy/service-role/AmazonECSTaskExecutionRolePolicy"
}

# ECS task definition
resource "aws_ecs_task_definition" "test" {
  family                   = "redis-cluster-test"
  requires_compatibilities = ["FARGATE"]
  network_mode             = "awsvpc"
  cpu                      = "256"
  memory                   = "512"
  execution_role_arn       = aws_iam_role.ecs_task_execution.arn
  runtime_platform {
    cpu_architecture        = "ARM64"
    operating_system_family = "LINUX"
  }

  container_definitions = jsonencode([
    {
      name  = "test"
      image = "${aws_ecr_repository.test.repository_url}:latest"
      environment = [
        {
          name  = "VALKEY_ENDPOINT"
          value = aws_elasticache_serverless_cache.valkey.endpoint[0].address
        },
        {
          name  = "VALKEY_PORT"
          value = "6379"
        }
      ]
      logConfiguration = {
        logDriver = "awslogs"
        options = {
          "awslogs-group"         = aws_cloudwatch_log_group.fargate.name
          "awslogs-region"        = var.aws_region
          "awslogs-stream-prefix" = "test"
        }
      }
    }
  ])
}
