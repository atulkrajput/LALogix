import { describe, it, expect, vi, beforeEach } from 'vitest';

const mockUserFindUnique = vi.fn();
const mockVerifyPassword = vi.fn();
const mockSignToken = vi.fn();

vi.mock('@/lib/prisma', () => ({
  default: {
    user: {
      findUnique: mockUserFindUnique,
    },
  },
}));

vi.mock('@/lib/auth', () => ({
  verifyPassword: mockVerifyPassword,
  signToken: mockSignToken,
}));

describe('POST /api/auth/login', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('returns the underlying error message in non-production environments', async () => {
    mockUserFindUnique.mockRejectedValue(new Error('Database connection failed'));

    const { POST } = await import('@/app/api/auth/login/route');
    const req = new Request('http://localhost/api/auth/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email: 'admin@lalogix.com', password: 'secret' }),
    }) as any;

    const res = await POST(req);
    const payload = await res.json();

    expect(res.status).toBe(500);
    expect(payload.error).toContain('Database connection failed');
  });
});
