# Cache::flexible() fails on AWS ElastiCache Serverless (Valkey)

This repository contains a minimal reproduction case for Laravel's `Cache::flexible()` method failing on AWS ElastiCache Serverless (Valkey) with phpredis driver, regardless of connection mode (single-node or cluster).

## Issue Description

Laravel's `Cache::flexible()` method does not work on AWS ElastiCache Serverless (Valkey) with phpredis driver, using either connection mode:

- **Single-node connection (default)**: Fails with CROSSSLOT error
- **Cluster mode connection (clusters)**: Fails with "Error processing response from Redis node!"

Valkey Serverless has `cluster_enabled: 1` but provides a single endpoint. It's unclear which connection mode should be used. Both modes fail with different errors.

**Note**: This test also includes predis patterns for completeness, but predis does not support `Cache::flexible()` on any Redis Cluster environment (not specific to Valkey Serverless) due to lack of MGET support in cluster mode.

## Environment

- **PHP**: 8.2.29
- **Laravel Packages**:
  - illuminate/cache: ^12.0
  - illuminate/redis: ^12.0
  - illuminate/support: ^12.0
  - predis/predis: ^2.0
- **PHP Extensions**: phpredis 6.2.0
- **Redis**: AWS ElastiCache Serverless (Valkey) with cluster_enabled: 1, TLS required

## Reproduction

This repository includes Terraform configuration to provision AWS ElastiCache Serverless (Valkey) and ECS Fargate to run the test script.

### Prerequisites

- Terraform 1.0+
- AWS CLI configured with appropriate credentials
- Docker

### Steps

#### 1. Set AWS Credentials

```bash
export AWS_PROFILE=your-profile
export AWS_REGION=ap-northeast-1  # or your preferred region
export TF_VAR_aws_region=$AWS_REGION
```

#### 2. Build and Push Docker Image

```bash
cd terraform/fargate

# Build the Docker image
docker build -t redis-cluster-test:latest .

# Get your AWS account ID
ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text)

# Login to ECR
aws ecr get-login-password --region $AWS_REGION | \
  docker login --username AWS --password-stdin ${ACCOUNT_ID}.dkr.ecr.${AWS_REGION}.amazonaws.com

# Tag and push
docker tag redis-cluster-test:latest ${ACCOUNT_ID}.dkr.ecr.${AWS_REGION}.amazonaws.com/redis-cluster-test:latest
docker push ${ACCOUNT_ID}.dkr.ecr.${AWS_REGION}.amazonaws.com/redis-cluster-test:latest

cd ../..
```

#### 3. Deploy Infrastructure

```bash
cd terraform
terraform init
terraform apply
cd ..
```

Deployment takes approximately 5-10 minutes.

#### 4. View Test Results

The ECS task runs automatically after deployment. View the output in CloudWatch Logs:

```bash
aws logs tail /ecs/redis-cluster-test --follow --region $AWS_REGION
```

#### 5. Cleanup

```bash
# Delete ECR images first
aws ecr batch-delete-image \
  --repository-name redis-cluster-test \
  --image-ids imageTag=latest \
  --region $AWS_REGION

# Destroy infrastructure
cd terraform
terraform destroy
cd ..
```

### Test Script

The test script `terraform/fargate/test-valkey-cluster.php` tests all 4 connection patterns:

1. **Pattern 1**: phpredis + default (single node connection)
2. **Pattern 2**: phpredis + clusters (cluster mode connection)
3. **Pattern 3**: predis + default (single node connection)
4. **Pattern 4**: predis + clusters (cluster mode connection)

Each pattern attempts to call `Cache::flexible()` with a simple test key.

**Focus**: Patterns 1 and 2 (phpredis) both fail on Valkey Serverless with different errors. Patterns 3 and 4 (predis) are included for completeness but are known not to work on any Redis Cluster environment.

## Expected Behavior

`Cache::flexible()` should successfully store and retrieve values on AWS ElastiCache Serverless (Valkey) with phpredis driver, using an appropriate connection mode.

## Actual Behavior

All 4 patterns fail with different errors.

**phpredis patterns (1 & 2)**: Both fail on Valkey Serverless, with different errors depending on connection mode. It's unclear which mode is appropriate for Valkey Serverless.

**predis patterns (3 & 4)**: Known to fail on any Redis Cluster environment (not Valkey Serverless-specific).

### Pattern 1: phpredis + default (single node)

**Error**: `CROSSSLOT Keys in request don't hash to the same slot`

```
TypeError: array_map(): Argument #2 ($array) must be of type array, bool given
File: vendor/illuminate/redis/Connections/PhpRedisConnection.php:68

Redis last error: CROSSSLOT Keys in request don't hash to the same slot
```

**Details**: `Cache::flexible()` internally calls `many()` which uses `MGET` with two keys that hash to different slots in Redis Cluster, resulting in a CROSSSLOT error from Redis server.

### Pattern 2: phpredis + clusters (cluster mode)

**Error**: `Error processing response from Redis node!`

```
RedisClusterException: Error processing response from Redis node!
File: vendor/illuminate/redis/Connections/Connection.php:116

Redis last error: none
```

**Details**:
- This pattern uses phpredis's RedisCluster class, which is designed for Redis Cluster environments
- RedisCluster object initializes successfully and `_masters()` returns node information correctly
- However, when executing commands (including basic `SET`), phpredis fails with "Error processing response from Redis node!"
- This appears to be a Valkey Serverless-specific issue, as phpredis RedisCluster works with standard Redis Cluster environments

### Pattern 3: predis + default (single node)

**Error**: `CROSSSLOT Keys in request don't hash to the same slot`

```
Predis\Response\ServerException: CROSSSLOT Keys in request don't hash to the same slot
File: vendor/predis/predis/src/Client.php:416

Error type: CROSSSLOT
```

**Details**: Same CROSSSLOT issue as Pattern 1. Predis correctly reports the Redis server error.

### Pattern 4: predis + clusters (cluster mode)

**Error**: `Cannot use 'MGET' with redis-cluster`

```
Predis\NotSupportedException: Cannot use 'MGET' with redis-cluster.
File: vendor/predis/predis/src/Connection/Cluster/RedisCluster.php:363
```

**Details**: Predis's RedisCluster implementation does not support the MGET command. This is a known limitation of predis and occurs on any Redis Cluster environment, not specific to Valkey Serverless.

## Summary

`Cache::flexible()` does not work on AWS ElastiCache Serverless (Valkey) with phpredis driver.

### phpredis Connection Modes

Valkey Serverless provides a single endpoint but has `cluster_enabled: 1`. The appropriate connection mode is unclear:

**Single-node connection (Pattern 1)**:
- `Cache::flexible()` stores two keys: the value (`illuminate:cache:{key}`) and timestamp (`illuminate:cache:flexible:created:{key}`)
- These keys hash to different slots in Redis Cluster
- `MGET` with cross-slot keys results in `CROSSSLOT` error

**Cluster mode connection (Pattern 2)**:
- Uses phpredis RedisCluster class
- Initialization succeeds, but command execution fails with "Error processing response from Redis node!"
- This error occurs even with basic `SET` commands
- Appears to be Valkey Serverless-specific (standard Redis Cluster works with phpredis RedisCluster)

### predis Limitation (Patterns 3 & 4)

Predis does not support MGET in cluster mode on any Redis Cluster environment. This is a known limitation of the predis library, not specific to Valkey Serverless.

## Repository Structure

```
.
├── README.md                           # This file
├── terraform/
│   ├── main.tf                         # Terraform infrastructure configuration
│   ├── variables.tf                    # Terraform variables
│   ├── outputs.tf                      # Terraform outputs
│   └── fargate/
│       ├── Dockerfile                  # Container image definition
│       ├── composer.json               # PHP dependencies
│       ├── database.php                # Redis connection configuration
│       └── test-valkey-cluster.php     # Test script (reproduces the issue)
```

**Key files**:
- `terraform/fargate/test-valkey-cluster.php`: Main test script that calls `Cache::flexible()` with all 4 patterns
- `terraform/fargate/database.php`: Redis connection configuration (supports both single-node and cluster modes)
- `terraform/main.tf`: AWS infrastructure (Valkey Serverless, ECS Fargate, VPC, etc.)

## Context

This report documents that `Cache::flexible()` fails on Valkey Serverless with phpredis using both connection modes:

- **Pattern 1 (phpredis + default)**: CROSSSLOT error - occurs on any Redis Cluster environment
- **Pattern 2 (phpredis + clusters)**: "Error processing response" - Valkey Serverless-specific issue
- **Pattern 3 & 4 (predis)**: Known predis limitation - affects all Redis Cluster environments

Which connection mode should be used for Valkey Serverless is a question for Laravel/phpredis maintainers to determine.
