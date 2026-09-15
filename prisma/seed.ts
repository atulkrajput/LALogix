import { PrismaClient } from '@prisma/client';
import bcrypt from 'bcryptjs';

const prisma = new PrismaClient();

async function main() {
  // Create default admin user
  const adminPassword = await bcrypt.hash('admin123', 12);
  await prisma.user.upsert({
    where: { email: 'admin@lalogix.com' },
    update: {},
    create: {
      name: 'Admin',
      email: 'admin@lalogix.com',
      password: adminPassword,
      role: 'admin',
    },
  });
  console.log('Default admin created: admin@lalogix.com / admin123');
}

main()
  .catch(console.error)
  .finally(() => prisma.$disconnect());
