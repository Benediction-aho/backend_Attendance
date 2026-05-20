require('dotenv').config();
const express = require('express');
const cors = require('cors');
const mongoose = require('mongoose'); // ← AJOUTER
const swaggerUi = require('swagger-ui-express');
const connectDB = require('./config/db');
const swaggerSpec = require('./config/swagger');
const errorHandler = require('./middleware/errorHandler');

const authRoutes = require('./routes/authRoutes');
const attendanceRoutes = require('./routes/attendanceRoutes');
const taskRoutes = require('./routes/taskRoutes');
const adminRoutes = require('./routes/adminRoutes');
const statsRoutes = require('./routes/statsRoutes');

const app = express();

app.set('etag', false); // ← AJOUTER : évite les réponses 304 stale

connectDB();

// ← AJOUTER : nettoie les index stales au démarrage
mongoose.connection.once('open', async () => {
  try {
    const Attendance = require('./models/Attendance');
    const indexes = await Attendance.collection.indexes();

    const stalesToDrop = indexes.filter(idx => {
      const keys = Object.keys(idx.key);
      // Supprimer tout index qui contient "employee" (ancien champ)
      return keys.includes('employee');
    });

    for (const idx of stalesToDrop) {
      await Attendance.collection.dropIndex(idx.name);
      console.log(`🗑️  Index stale supprimé : ${idx.name}`);
    }

    // Recréer les bons index depuis le schéma
    await Attendance.syncIndexes();

    const finalIndexes = await Attendance.collection.indexes();
    console.log('✅ Index Attendance OK :',
      finalIndexes.map(i => `${i.name}${i.unique ? ' (unique)' : ''}`)
    );
  } catch (err) {
    console.error('⚠️ Index sync error:', err.message);
  }
});

app.use(cors({
  origin: process.env.CLIENT_URL || '*',
  credentials: true,
}));
app.use(express.json());
app.use(express.urlencoded({ extended: true }));

app.use('/api/docs', swaggerUi.serve, swaggerUi.setup(swaggerSpec));
app.get('/api/openapi.json', (req, res) => res.json(swaggerSpec));

app.use('/api/auth', authRoutes);
app.use('/api/attendance', attendanceRoutes);
app.use('/api/tasks', taskRoutes);
app.use('/api/admin', adminRoutes);
app.use('/api/stats', statsRoutes);

app.get('/api/health', (req, res) => {
  res.json({ success: true, message: 'Assistance Ghana API is running', timestamp: new Date() });
});

app.use((req, res) => {
  res.status(404).json({ success: false, message: 'Route not found' });
});

app.use(errorHandler);

const PORT = process.env.PORT || 5000;
app.listen(PORT, () => {
  console.log(`🚀 Server running on port ${PORT}`);
  console.log(`📚 API Docs: http://localhost:${PORT}/api/docs`);
});

module.exports = app;