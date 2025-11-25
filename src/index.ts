import express from 'express';
import fetch from 'node-fetch';
import cors from 'cors';

const app = express();
const PORT = process.env.PORT || 3000;
const WORDPRESS_URL = process.env.WORDPRESS_URL || 'http://{{SITE_DOMAIN}}';
const SITE_NAME = process.env.SITE_NAME || '{{SITE_NAME}}';
const SITE_ID = process.env.SITE_ID || '{{SITE_ID}}';

// Test
// Middleware
app.use(express.json());
app.use(cors({
  origin: WORDPRESS_URL,
  credentials: true
}));

// Logging middleware
app.use((req, _res, next) => {
  console.log(`[${new Date().toISOString()}] ${req.method} ${req.path}`);
  next();
});

// ===============================
// Health & Status Endpoints
// ===============================

app.get('/health', (_req, res) => {
  res.json({
    status: 'healthy',
    wordpress: WORDPRESS_URL,
    site: SITE_NAME,
    siteId: SITE_ID,
    timestamp: new Date().toISOString(),
    uptime: process.uptime(),
    memory: process.memoryUsage()
  });
});

// ===============================
// WordPress Integration Endpoints
// ===============================

// Receive webhooks from WordPress
app.post('/webhook', async (req, res) => {
  const { event, data } = req.body;
  console.log(`Received ${event} from WordPress:`, data);

  // Process different WordPress events
  switch(event) {
    case 'post_published':
      // Handle new post published
      console.log(`New post published: ${data.title}`);
      break;

    case 'user_registered':
      // Handle new user registration
      console.log(`New user registered: ${data.email}`);
      break;

    case 'order_completed':
      // Handle WooCommerce order
      console.log(`Order completed: ${data.order_id}`);
      break;

    default:
      console.log(`Unknown event: ${event}`);
  }

  res.json({
    received: true,
    event,
    timestamp: new Date().toISOString()
  });
});

// Sync with WordPress REST API
app.get('/sync', async (_req, res) => {
  try {
    console.log(`Syncing with WordPress at ${WORDPRESS_URL}`);

    const wpResponse = await fetch(`${WORDPRESS_URL}/wp-json/wp/v2/posts?per_page=10`);

    if (!wpResponse.ok) {
      throw new Error(`WordPress API returned ${wpResponse.status}`);
    }

    const posts = await wpResponse.json() as any[];

    res.json({
      success: true,
      postCount: posts.length,
      posts: posts.map(post => ({
        id: post.id,
        title: post.title.rendered,
        date: post.date,
        link: post.link
      })),
      wordpress: WORDPRESS_URL,
      timestamp: new Date().toISOString()
    });
  } catch (error: any) {
    console.error('Sync error:', error);
    res.status(500).json({
      error: error.message,
      wordpress: WORDPRESS_URL
    });
  }
});

// Call WordPress custom endpoint (via our bridge plugin)
app.post('/call-wordpress', async (req, res) => {
  try {
    const { endpoint, method = 'GET', data } = req.body;

    const wpResponse = await fetch(`${WORDPRESS_URL}/wp-json/bridge/v1/${endpoint}`, {
      method,
      headers: {
        'Content-Type': 'application/json'
      },
      body: method !== 'GET' ? JSON.stringify(data) : undefined
    });

    const result = await wpResponse.json();
    res.json({
      success: wpResponse.ok,
      result,
      statusCode: wpResponse.status
    });
  } catch (error: any) {
    res.status(500).json({ error: error.message });
  }
});

// ===============================
// AI & Processing Endpoints
// ===============================

// Example AI processing endpoint
app.post('/ai/process', async (req, res) => {
  const { prompt, context } = req.body;

  // Here you would integrate with AI services like:
  // - OpenAI GPT
  // - Anthropic Claude
  // - Google PaLM
  // - Local LLMs

  // For now, just echo back with mock processing
  res.json({
    result: `AI Processing: ${prompt}`,
    site: SITE_NAME,
    context,
    model: 'mock-ai-v1',
    timestamp: new Date().toISOString()
  });
});

// Background job processing
app.post('/jobs/queue', async (req, res) => {
  const { jobType } = req.body;

  // Here you would integrate with job queues like:
  // - Bull
  // - Bee-Queue
  // - Kue
  // - RabbitMQ

  const jobId = `job-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;

  console.log(`Queued job ${jobId}: ${jobType}`);

  res.json({
    queued: true,
    jobId,
    jobType,
    status: 'pending',
    timestamp: new Date().toISOString()
  });
});

// ===============================
// Data Processing Endpoints
// ===============================

// Process CSV/Excel data
app.post('/data/import', async (req, res) => {
  const { format, data } = req.body;

  // Here you would process various data formats
  // and potentially import to WordPress

  res.json({
    success: true,
    format,
    rowCount: Array.isArray(data) ? data.length : 0,
    message: 'Data import endpoint ready for implementation'
  });
});

// Generate reports
app.get('/reports/:type', async (req, res) => {
  const { type } = req.params;

  // Generate various reports from WordPress data
  res.json({
    report: type,
    site: SITE_NAME,
    generated: new Date().toISOString(),
    message: 'Report generation endpoint ready for implementation'
  });
});

// ===============================
// Utility Endpoints
// ===============================

// Get service configuration
app.get('/config', (_req, res) => {
  res.json({
    site: SITE_NAME,
    siteId: SITE_ID,
    wordpress: WORDPRESS_URL,
    port: PORT,
    environment: process.env.NODE_ENV || 'development',
    features: {
      ai: true,
      jobs: true,
      sync: true,
      webhooks: true
    }
  });
});

// Clear caches
app.post('/cache/clear', (_req, res) => {
  // Implement cache clearing logic
  console.log('Clearing caches...');

  res.json({
    success: true,
    message: 'Caches cleared',
    timestamp: new Date().toISOString()
  });
});

// ===============================
// Error Handling
// ===============================

// 404 handler
app.use((req, res) => {
  res.status(404).json({
    error: 'Endpoint not found',
    path: req.path,
    method: req.method
  });
});

// Global error handler
app.use((err: any, _req: any, res: any, _next: any) => {
  console.error('Error:', err);
  res.status(500).json({
    error: err.message || 'Internal server error',
    timestamp: new Date().toISOString()
  });
});

// ===============================
// Server Startup
// ===============================

app.listen(PORT, () => {
  console.log('=========================================');
  console.log('Node-WordPress Bridge Service Started');
  console.log('=========================================');
  console.log(`Port: ${PORT}`);
  console.log(`WordPress: ${WORDPRESS_URL}`);
  console.log(`Site: ${SITE_NAME}`);
  console.log(`Site ID: ${SITE_ID}`);
  console.log('=========================================');
  console.log('Available endpoints:');
  console.log('  GET  /health          - Service health check');
  console.log('  GET  /sync            - Sync with WordPress');
  console.log('  POST /webhook         - Receive WordPress webhooks');
  console.log('  POST /ai/process      - AI processing');
  console.log('  POST /jobs/queue      - Queue background jobs');
  console.log('  GET  /config          - Get service configuration');
  console.log('=========================================');
});