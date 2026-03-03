/**
 * Script de création de l'admin initial
 * Usage: node scripts/seedAdmin.js
 *
 * Ce script crée un compte admin avec un mot de passe correctement hashé par bcrypt.
 * À utiliser UNE SEULE FOIS pour initialiser la base de données.
 */

require('dotenv').config();
const mongoose = require('mongoose');
const bcrypt = require('bcryptjs');

// Config admin initial — MODIFIEZ CES VALEURS AVANT D'EXÉCUTER
const ADMIN_DATA = {
  firstName: 'Ayodele',
  lastName: 'AHOUANSE',
  email: 'admin@gimaservices.com',
  password: 'Assistanceghana@2026',   // Modifiez ce mot de passe !
  role: 'admin',
  employeeType: 'employe',
  position: 'Administrateur Système',
  isBlocked: false,
  isDeleted: false,
};

const run = async () => {
  try {
    console.log('🔌 Connexion à MongoDB...');
    await mongoose.connect(process.env.MONGODB_URI);
    console.log('✅ Connecté à MongoDB');

    // Vérifier si l'admin existe déjà
    const existing = await mongoose.connection.db
      .collection('users')
      .findOne({ email: ADMIN_DATA.email });

    if (existing) {
      console.log(`⚠️  Un utilisateur avec l'email "${ADMIN_DATA.email}" existe déjà.`);
      console.log('   Rôle actuel :', existing.role);

      // Si l'utilisateur existe mais n'est pas admin, le mettre à jour
      if (existing.role !== 'admin') {
        const hashedPassword = await bcrypt.hash(ADMIN_DATA.password, 12);
        await mongoose.connection.db.collection('users').updateOne(
          { email: ADMIN_DATA.email },
          {
            $set: {
              role: 'admin',
              password: hashedPassword,
              isBlocked: false,
              isDeleted: false,
            },
          }
        );
        console.log('✅ Utilisateur mis à jour en ADMIN avec mot de passe hashé.');
      } else {
        // Mettre à jour seulement le mot de passe (re-hash)
        const hashedPassword = await bcrypt.hash(ADMIN_DATA.password, 12);
        await mongoose.connection.db.collection('users').updateOne(
          { email: ADMIN_DATA.email },
          {
            $set: {
              password: hashedPassword,
              isBlocked: false,
              isDeleted: false,
            },
          }
        );
        console.log('✅ Mot de passe admin réinitialisé (bcrypt hash mis à jour).');
      }
    } else {
      // Créer l'admin avec mot de passe hashé
      const hashedPassword = await bcrypt.hash(ADMIN_DATA.password, 12);

      await mongoose.connection.db.collection('users').insertOne({
        ...ADMIN_DATA,
        password: hashedPassword,
        createdAt: new Date(),
        updatedAt: new Date(),
      });

      console.log('✅ Admin créé avec succès !');
    }

    console.log('\n📋 Credentials de connexion :');
    console.log('   Email    :', ADMIN_DATA.email);
    console.log('   Password :', ADMIN_DATA.password);
    console.log('   Role     : admin');
    console.log('\n🔐 Connectez-vous sur : /admin/login\n');

    await mongoose.disconnect();
    process.exit(0);
  } catch (error) {
    console.error('❌ Erreur :', error.message);
    await mongoose.disconnect();
    process.exit(1);
  }
};

run();
