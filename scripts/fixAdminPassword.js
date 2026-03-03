/**
 * Script de réparation : re-hasher le mot de passe d'un admin existant
 * Usage: node scripts/fixAdminPassword.js
 *
 * À utiliser si tu as créé l'admin manuellement via Postman/MongoDB Compass
 * avec un mot de passe en clair (non hashé). Ce script le corrige.
 */

require('dotenv').config();
const mongoose = require('mongoose');
const bcrypt = require('bcryptjs');

// ⚠️ MODIFIEZ CES VALEURS avec l'email et le NOUVEAU mot de passe souhaité
const TARGET_EMAIL = 'admin@gimaservices.com';  // L'email de votre admin
const NEW_PASSWORD = 'Assistanceghana@2026';              // Le mot de passe que vous voulez utiliser

const run = async () => {
  try {
    console.log('🔌 Connexion à MongoDB...');
    await mongoose.connect(process.env.MONGODB_URI);
    console.log('✅ Connecté');

    const user = await mongoose.connection.db
      .collection('users')
      .findOne({ email: TARGET_EMAIL });

    if (!user) {
      console.log(`❌ Aucun utilisateur trouvé avec l'email : ${TARGET_EMAIL}`);
      console.log('   → Lancez plutôt : node scripts/seedAdmin.js');
      process.exit(1);
    }

    console.log('👤 Utilisateur trouvé :');
    console.log('   Nom  :', user.firstName, user.lastName);
    console.log('   Role :', user.role);
    console.log('   Bloqué :', user.isBlocked);
    console.log('   Supprimé :', user.isDeleted);

    // Vérifier si le mot de passe actuel est déjà un hash bcrypt
    const isBcrypt = user.password && user.password.startsWith('$2');
    console.log('   Password hashé (bcrypt) :', isBcrypt ? 'OUI' : '❌ NON — correction en cours...');

    // Hasher le nouveau mot de passe
    const hashedPassword = await bcrypt.hash(NEW_PASSWORD, 12);

    // Mettre à jour l'utilisateur
    await mongoose.connection.db.collection('users').updateOne(
      { email: TARGET_EMAIL },
      {
        $set: {
          password: hashedPassword,
          role: 'admin',           // S'assurer que le rôle est admin
          isBlocked: false,
          isDeleted: false,
          updatedAt: new Date(),
        },
      }
    );

    console.log('\n✅ Mot de passe mis à jour avec succès (bcrypt hashé) !');
    console.log('\n📋 Nouveaux credentials :');
    console.log('   Email    :', TARGET_EMAIL);
    console.log('   Password :', NEW_PASSWORD);
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
