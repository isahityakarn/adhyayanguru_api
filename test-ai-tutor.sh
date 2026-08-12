#!/bin/bash

# AI Tutor API Testing Script
# Make sure to set your AUTH_TOKEN before running

# Colors for output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Configuration
API_BASE="http://localhost:8000/api"
AUTH_TOKEN="${AUTH_TOKEN:-YOUR_TOKEN_HERE}"

# Check if token is set
if [ "$AUTH_TOKEN" = "YOUR_TOKEN_HERE" ]; then
    echo -e "${RED}Error: Please set your AUTH_TOKEN${NC}"
    echo "Usage: AUTH_TOKEN=your_token_here ./test-ai-tutor.sh"
    echo "Or: export AUTH_TOKEN=your_token_here"
    exit 1
fi

echo -e "${BLUE}=== AI Tutor API Testing ===${NC}\n"

# Test 1: Simple Chat
echo -e "${GREEN}Test 1: Simple Chat${NC}"
curl -X POST "$API_BASE/ai-tutor/chat" \
  -H "Authorization: Bearer $AUTH_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "message": "What is the Pythagorean theorem?"
  }' | jq '.'

echo -e "\n---\n"

# Test 2: Chat with Context
echo -e "${GREEN}Test 2: Chat with Context${NC}"
curl -X POST "$API_BASE/ai-tutor/chat" \
  -H "Authorization: Bearer $AUTH_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "message": "Explain photosynthesis",
    "context": {
      "subject": "Biology",
      "chapter": "Plant Physiology",
      "topic": "Photosynthesis"
    }
  }' | jq '.'

echo -e "\n---\n"

# Test 3: Explain Topic
echo -e "${GREEN}Test 3: Explain Topic${NC}"
curl -X POST "$API_BASE/ai-tutor/explain" \
  -H "Authorization: Bearer $AUTH_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "topic": "Quadratic Equations",
    "subject": "Mathematics",
    "detail_level": "intermediate"
  }' | jq '.'

echo -e "\n---\n"

# Test 4: Generate Questions
echo -e "${GREEN}Test 4: Generate Practice Questions${NC}"
curl -X POST "$API_BASE/ai-tutor/questions" \
  -H "Authorization: Bearer $AUTH_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "topic": "Newton'\''s Laws of Motion",
    "subject": "Physics",
    "difficulty": "medium",
    "count": 3
  }' | jq '.'

echo -e "\n${BLUE}=== Testing Complete ===${NC}"
