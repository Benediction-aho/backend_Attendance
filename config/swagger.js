const swaggerJsdoc = require('swagger-jsdoc');

const options = {
  definition: {
    openapi: '3.0.0',
    info: {
      title: 'Assistance Ghana Attendance API',
      version: '1.0.0',
      description: 'Enterprise-grade attendance and productivity management API',
      contact: {
        name: 'GIMA Services',
      },
    },
    servers: [
      {
        url: process.env.NODE_ENV === 'production'
          ? 'https://your-backend.render.com/api'
          : 'http://localhost:5000/api',
        description: process.env.NODE_ENV === 'production' ? 'Production' : 'Development',
      },
    ],
    components: {
      securitySchemes: {
        bearerAuth: {
          type: 'http',
          scheme: 'bearer',
          bearerFormat: 'JWT',
        },
      },
      schemas: {
        User: {
          type: 'object',
          properties: {
            _id: { type: 'string' },
            firstName: { type: 'string' },
            lastName: { type: 'string' },
            email: { type: 'string' },
            role: { type: 'string', enum: ['admin', 'employee'] },
            employeeType: { type: 'string', enum: ['stagiaire', 'employe'] },
            position: { type: 'string' },
            isBlocked: { type: 'boolean' },
            createdAt: { type: 'string', format: 'date-time' },
          },
        },
        Task: {
          type: 'object',
          properties: {
            _id: { type: 'string' },
            userId: { type: 'string' },
            description: { type: 'string' },
            difficulties: { type: 'string' },
            status: { type: 'string', enum: ['completed', 'pending'] },
            date: { type: 'string', format: 'date' },
            createdAt: { type: 'string', format: 'date-time' },
          },
        },
        Attendance: {
          type: 'object',
          properties: {
            _id: { type: 'string' },
            userId: { type: 'string' },
            date: { type: 'string', format: 'date' },
            checkInTime: { type: 'string' },
            checkOutTime: { type: 'string' },
            hoursWorked: { type: 'number' },
            isLate: { type: 'boolean' },
            earlyLeave: { type: 'boolean' },
          },
        },
        Error: {
          type: 'object',
          properties: {
            success: { type: 'boolean', example: false },
            message: { type: 'string' },
          },
        },
      },
    },
    security: [{ bearerAuth: [] }],
  },
  apis: ['./routes/*.js'],
};

module.exports = swaggerJsdoc(options);
