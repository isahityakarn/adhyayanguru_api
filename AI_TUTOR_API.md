# AI Tutor API Documentation

This API integrates Google Gemini AI to provide an intelligent tutoring system for students.

## Setup

1. Get your Google Gemini API key from [Google AI Studio](https://makersuite.google.com/app/apikey)

2. Add the API key to your `.env` file:
```env
GEMINI_API_KEY=your_api_key_here
```

3. Clear config cache:
```bash
php artisan config:clear
```

## Available Endpoints

All endpoints require authentication (`Authorization: Bearer {token}`).

### 1. Chat with AI Tutor

**Endpoint:** `POST /api/ai-tutor/chat`

Interactive conversation with the AI tutor, maintaining context across messages.

**Request Body:**
```json
{
  "message": "Can you explain photosynthesis?",
  "context": {
    "subject": "Biology",
    "chapter": "Plant Physiology",
    "topic": "Photosynthesis"
  },
  "conversation_history": [
    {
      "role": "user",
      "content": "What is photosynthesis?"
    },
    {
      "role": "assistant",
      "content": "Photosynthesis is the process by which plants..."
    }
  ]
}
```

**Parameters:**
- `message` (required): The user's question or message
- `context` (optional): Educational context for better responses
  - `subject`: Subject name
  - `chapter`: Chapter name
  - `topic`: Specific topic name
- `conversation_history` (optional): Array of previous messages to maintain context

**Response:**
```json
{
  "response": "Photosynthesis is the process by which green plants...",
  "usage": {
    "prompt_tokens": 150,
    "completion_tokens": 200,
    "total_tokens": 350
  }
}
```

### 2. Explain Topic

**Endpoint:** `POST /api/ai-tutor/explain`

Generate a structured explanation for a specific topic.

**Request Body:**
```json
{
  "topic": "Quadratic Equations",
  "subject": "Mathematics",
  "detail_level": "intermediate"
}
```

**Parameters:**
- `topic` (required): The topic to explain
- `subject` (optional): Subject context
- `detail_level` (optional): `basic`, `intermediate`, or `advanced` (default: `intermediate`)

**Response:**
```json
{
  "topic": "Quadratic Equations",
  "explanation": "## Overview\nQuadratic equations are polynomial equations..."
}
```

### 3. Generate Practice Questions

**Endpoint:** `POST /api/ai-tutor/questions`

Generate practice questions with answers and explanations.

**Request Body:**
```json
{
  "topic": "Newton's Laws of Motion",
  "subject": "Physics",
  "difficulty": "medium",
  "count": 5
}
```

**Parameters:**
- `topic` (required): The topic for questions
- `subject` (optional): Subject context
- `difficulty` (optional): `easy`, `medium`, or `hard` (default: `medium`)
- `count` (optional): Number of questions (1-10, default: 5)

**Response:**
```json
{
  "topic": "Newton's Laws of Motion",
  "difficulty": "medium",
  "questions": "1. Question: What is Newton's First Law?\nAnswer: ..."
}
```

## Usage Examples

### Example 1: Simple Chat
```bash
curl -X POST http://localhost:8000/api/ai-tutor/chat \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "message": "What is the Pythagorean theorem?"
  }'
```

### Example 2: Chat with Context
```bash
curl -X POST http://localhost:8000/api/ai-tutor/chat \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "message": "Can you give me an example?",
    "context": {
      "subject": "Mathematics",
      "chapter": "Geometry",
      "topic": "Pythagorean Theorem"
    },
    "conversation_history": [
      {
        "role": "user",
        "content": "What is the Pythagorean theorem?"
      },
      {
        "role": "assistant",
        "content": "The Pythagorean theorem states that in a right triangle..."
      }
    ]
  }'
```

### Example 3: Explain Topic
```bash
curl -X POST http://localhost:8000/api/ai-tutor/explain \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "topic": "DNA Replication",
    "subject": "Biology",
    "detail_level": "advanced"
  }'
```

### Example 4: Generate Questions
```bash
curl -X POST http://localhost:8000/api/ai-tutor/questions \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "topic": "Chemical Bonding",
    "subject": "Chemistry",
    "difficulty": "hard",
    "count": 3
  }'
```

## Error Responses

### 401 Unauthorized
```json
{
  "message": "Unauthenticated."
}
```

### 422 Validation Error
```json
{
  "message": "The message field is required.",
  "errors": {
    "message": ["The message field is required."]
  }
}
```

### 500 Server Error
```json
{
  "message": "Failed to get response from AI tutor.",
  "error": "API key not valid."
}
```

## Notes

- The AI tutor uses Google Gemini 1.5 Flash model
- Responses are educational-focused and structured
- Conversation history helps maintain context across multiple exchanges
- Token usage is returned for monitoring purposes
- All requests timeout after 30 seconds
- Maximum message length is 5000 characters
- Maximum output is 2048 tokens per response

## Integration Tips

1. **Maintain Conversation History**: Store the conversation in your frontend state and send it with each request for better context
2. **Use Context**: Always provide subject/chapter/topic context when available for more relevant responses
3. **Handle Errors**: Implement proper error handling for network issues and API failures
4. **Rate Limiting**: Consider implementing rate limiting to manage API costs
5. **Caching**: Cache explanations for common topics to reduce API calls
