require('dotenv').config();
const swaggerSpec = require('../config/swagger');
const fs = require('fs');
const path = require('path');

const outputPath = path.join(__dirname, '..', 'openapi.json');
fs.writeFileSync(outputPath, JSON.stringify(swaggerSpec, null, 2));
console.log(`✅ OpenAPI spec generated: ${outputPath}`);
