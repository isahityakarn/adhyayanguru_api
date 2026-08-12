# AI Tutor Frontend Integration Example

## React/JavaScript Example

### 1. Create an AI Tutor Service

```javascript
// services/aiTutorService.js

const API_BASE_URL = 'http://localhost:8000/api';

class AiTutorService {
  constructor(authToken) {
    this.authToken = authToken;
  }

  async chat(message, context = null, conversationHistory = []) {
    const response = await fetch(`${API_BASE_URL}/ai-tutor/chat`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${this.authToken}`,
        'Accept': 'application/json',
      },
      body: JSON.stringify({
        message,
        context,
        conversation_history: conversationHistory,
      }),
    });

    if (!response.ok) {
      const error = await response.json();
      throw new Error(error.message || 'Failed to get AI response');
    }

    return await response.json();
  }

  async explainTopic(topic, subject = null, detailLevel = 'intermediate') {
    const response = await fetch(`${API_BASE_URL}/ai-tutor/explain`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${this.authToken}`,
        'Accept': 'application/json',
      },
      body: JSON.stringify({
        topic,
        subject,
        detail_level: detailLevel,
      }),
    });

    if (!response.ok) {
      const error = await response.json();
      throw new Error(error.message || 'Failed to get explanation');
    }

    return await response.json();
  }

  async generateQuestions(topic, subject = null, difficulty = 'medium', count = 5) {
    const response = await fetch(`${API_BASE_URL}/ai-tutor/questions`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${this.authToken}`,
        'Accept': 'application/json',
      },
      body: JSON.stringify({
        topic,
        subject,
        difficulty,
        count,
      }),
    });

    if (!response.ok) {
      const error = await response.json();
      throw new Error(error.message || 'Failed to generate questions');
    }

    return await response.json();
  }
}

export default AiTutorService;
```

### 2. React Component Example - Chat Interface

```jsx
// components/AiTutorChat.jsx

import React, { useState } from 'react';
import AiTutorService from '../services/aiTutorService';

function AiTutorChat({ authToken, context }) {
  const [messages, setMessages] = useState([]);
  const [inputMessage, setInputMessage] = useState('');
  const [isLoading, setIsLoading] = useState(false);

  const aiTutorService = new AiTutorService(authToken);

  const handleSendMessage = async (e) => {
    e.preventDefault();
    
    if (!inputMessage.trim()) return;

    // Add user message to UI
    const userMessage = {
      role: 'user',
      content: inputMessage,
    };
    
    setMessages(prev => [...prev, userMessage]);
    setInputMessage('');
    setIsLoading(true);

    try {
      // Send to API
      const response = await aiTutorService.chat(
        inputMessage,
        context,
        messages
      );

      // Add AI response to UI
      const aiMessage = {
        role: 'assistant',
        content: response.response,
      };
      
      setMessages(prev => [...prev, aiMessage]);
    } catch (error) {
      console.error('Error:', error);
      alert('Failed to get response from AI tutor');
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="ai-tutor-chat">
      <div className="chat-context">
        {context && (
          <div className="context-info">
            <strong>Subject:</strong> {context.subject} | 
            <strong>Chapter:</strong> {context.chapter}
          </div>
        )}
      </div>

      <div className="chat-messages">
        {messages.map((msg, index) => (
          <div key={index} className={`message ${msg.role}`}>
            <div className="message-role">
              {msg.role === 'user' ? 'You' : 'AI Tutor'}
            </div>
            <div className="message-content">{msg.content}</div>
          </div>
        ))}
        
        {isLoading && (
          <div className="message assistant loading">
            <div className="message-role">AI Tutor</div>
            <div className="message-content">Thinking...</div>
          </div>
        )}
      </div>

      <form onSubmit={handleSendMessage} className="chat-input-form">
        <input
          type="text"
          value={inputMessage}
          onChange={(e) => setInputMessage(e.target.value)}
          placeholder="Ask me anything..."
          disabled={isLoading}
          className="chat-input"
        />
        <button type="submit" disabled={isLoading || !inputMessage.trim()}>
          Send
        </button>
      </form>
    </div>
  );
}

export default AiTutorChat;
```

### 3. React Component Example - Topic Explanation

```jsx
// components/TopicExplainer.jsx

import React, { useState } from 'react';
import AiTutorService from '../services/aiTutorService';

function TopicExplainer({ authToken, topic, subject }) {
  const [explanation, setExplanation] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  const [detailLevel, setDetailLevel] = useState('intermediate');

  const aiTutorService = new AiTutorService(authToken);

  const handleExplain = async () => {
    setIsLoading(true);
    setExplanation('');

    try {
      const response = await aiTutorService.explainTopic(
        topic,
        subject,
        detailLevel
      );
      setExplanation(response.explanation);
    } catch (error) {
      console.error('Error:', error);
      alert('Failed to get explanation');
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="topic-explainer">
      <h2>Explain: {topic}</h2>
      
      <div className="controls">
        <select 
          value={detailLevel} 
          onChange={(e) => setDetailLevel(e.target.value)}
          disabled={isLoading}
        >
          <option value="basic">Basic</option>
          <option value="intermediate">Intermediate</option>
          <option value="advanced">Advanced</option>
        </select>
        
        <button onClick={handleExplain} disabled={isLoading}>
          {isLoading ? 'Generating...' : 'Explain'}
        </button>
      </div>

      {explanation && (
        <div className="explanation">
          <pre style={{ whiteSpace: 'pre-wrap' }}>{explanation}</pre>
        </div>
      )}
    </div>
  );
}

export default TopicExplainer;
```

### 4. React Component Example - Practice Questions

```jsx
// components/QuestionGenerator.jsx

import React, { useState } from 'react';
import AiTutorService from '../services/aiTutorService';

function QuestionGenerator({ authToken, topic, subject }) {
  const [questions, setQuestions] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  const [difficulty, setDifficulty] = useState('medium');
  const [count, setCount] = useState(5);

  const aiTutorService = new AiTutorService(authToken);

  const handleGenerate = async () => {
    setIsLoading(true);
    setQuestions('');

    try {
      const response = await aiTutorService.generateQuestions(
        topic,
        subject,
        difficulty,
        count
      );
      setQuestions(response.questions);
    } catch (error) {
      console.error('Error:', error);
      alert('Failed to generate questions');
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="question-generator">
      <h2>Practice Questions: {topic}</h2>
      
      <div className="controls">
        <div>
          <label>Difficulty:</label>
          <select 
            value={difficulty} 
            onChange={(e) => setDifficulty(e.target.value)}
            disabled={isLoading}
          >
            <option value="easy">Easy</option>
            <option value="medium">Medium</option>
            <option value="hard">Hard</option>
          </select>
        </div>

        <div>
          <label>Number of Questions:</label>
          <input
            type="number"
            min="1"
            max="10"
            value={count}
            onChange={(e) => setCount(parseInt(e.target.value))}
            disabled={isLoading}
          />
        </div>
        
        <button onClick={handleGenerate} disabled={isLoading}>
          {isLoading ? 'Generating...' : 'Generate Questions'}
        </button>
      </div>

      {questions && (
        <div className="questions">
          <pre style={{ whiteSpace: 'pre-wrap' }}>{questions}</pre>
        </div>
      )}
    </div>
  );
}

export default QuestionGenerator;
```

### 5. Usage in App

```jsx
// App.jsx or ChapterDetailPage.jsx

import React from 'react';
import AiTutorChat from './components/AiTutorChat';
import TopicExplainer from './components/TopicExplainer';
import QuestionGenerator from './components/QuestionGenerator';

function ChapterDetailPage() {
  const authToken = localStorage.getItem('authToken'); // Or from your auth context
  
  const context = {
    subject: 'Mathematics',
    chapter: 'Algebra',
    topic: 'Quadratic Equations'
  };

  return (
    <div className="chapter-detail-page">
      <h1>{context.chapter}</h1>
      
      {/* Chat Interface */}
      <section>
        <h2>Ask AI Tutor</h2>
        <AiTutorChat authToken={authToken} context={context} />
      </section>

      {/* Topic Explanation */}
      <section>
        <TopicExplainer 
          authToken={authToken} 
          topic={context.topic}
          subject={context.subject}
        />
      </section>

      {/* Practice Questions */}
      <section>
        <QuestionGenerator 
          authToken={authToken} 
          topic={context.topic}
          subject={context.subject}
        />
      </section>
    </div>
  );
}

export default ChapterDetailPage;
```

## Basic CSS Styling Example

```css
/* styles/AiTutor.css */

.ai-tutor-chat {
  max-width: 800px;
  margin: 0 auto;
  padding: 20px;
  border: 1px solid #ddd;
  border-radius: 8px;
}

.chat-context {
  background: #f5f5f5;
  padding: 10px;
  border-radius: 4px;
  margin-bottom: 20px;
}

.chat-messages {
  height: 400px;
  overflow-y: auto;
  margin-bottom: 20px;
  padding: 10px;
  background: #fafafa;
  border-radius: 4px;
}

.message {
  margin-bottom: 15px;
  padding: 10px;
  border-radius: 8px;
}

.message.user {
  background: #e3f2fd;
  margin-left: 20%;
}

.message.assistant {
  background: #f5f5f5;
  margin-right: 20%;
}

.message.loading {
  opacity: 0.6;
  font-style: italic;
}

.message-role {
  font-weight: bold;
  margin-bottom: 5px;
  font-size: 0.9em;
}

.chat-input-form {
  display: flex;
  gap: 10px;
}

.chat-input {
  flex: 1;
  padding: 10px;
  border: 1px solid #ddd;
  border-radius: 4px;
  font-size: 16px;
}

button {
  padding: 10px 20px;
  background: #4caf50;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
}

button:disabled {
  background: #ccc;
  cursor: not-allowed;
}

button:hover:not(:disabled) {
  background: #45a049;
}

.explanation,
.questions {
  margin-top: 20px;
  padding: 20px;
  background: #f9f9f9;
  border-radius: 8px;
  line-height: 1.6;
}
```

## Tips

1. **Store Auth Token Securely**: Use HttpOnly cookies or secure storage
2. **Error Handling**: Implement proper error boundaries and user feedback
3. **Loading States**: Show spinners or skeleton screens while waiting
4. **Rate Limiting**: Debounce user input to prevent excessive API calls
5. **Conversation Management**: Clear or reset conversation history when changing topics
6. **Markdown Rendering**: Consider using a markdown library to render formatted responses
7. **Auto-scroll**: Scroll to bottom when new messages arrive in chat
