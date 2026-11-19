import { useState } from 'react';
import Link from '@/components/ui/Link';
import ProductCard from './ProductCard';
import { WhatsAppButton, btn } from './Cta';
import { submitLead } from '@/lib/leads';
import { INDIAN_MOBILE, type ProductCard as Card } from '@/lib/site';
import { trackEvent } from '@/lib/analytics';

export interface QuizConfig {
  business_types: string[];
  sizes: string[];
  headaches: string[];
  rules: { business_type?: string; size?: string; headache?: string; product: string }[];
  fallback: string;
}

type Answers = { business_type: string; size: string; headache: string };

/** First rule whose named answers all match wins (rules live in config/lalogix.php). */
export function recommend(quiz: QuizConfig, a: Answers): string {
  const rule = quiz.rules.find((r) =>
    (['business_type', 'size', 'headache'] as const).every((k) => r[k] === undefined || r[k] === a[k]),
  );
  return rule?.product ?? quiz.fallback;
}

/** "Find your solution": 3 questions → name + phone → recommended product (saved as a lead). */
export default function Quiz({ quiz, products }: { quiz: QuizConfig; products: Card[] }) {
  const [step, setStep] = useState(0);
  const [answers, setAnswers] = useState<Answers>({ business_type: '', size: '', headache: '' });
  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [website, setWebsite] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [sending, setSending] = useState(false);
  const [result, setResult] = useState<Card | null>(null);

  const questions: { key: keyof Answers; label: string; options: string[] }[] = [
    { key: 'business_type', label: 'What kind of business do you run?', options: quiz.business_types },
    { key: 'size', label: 'How many people (students, customers or staff) do you handle?', options: quiz.sizes },
    { key: 'headache', label: 'What is your biggest headache today?', options: quiz.headaches },
  ];

  const choose = (key: keyof Answers, value: string) => {
    setAnswers((a) => ({ ...a, [key]: value }));
    setStep((s) => s + 1);
  };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (name.trim().length < 2) return setError('Please enter your name.');
    if (!INDIAN_MOBILE.test(phone.trim())) return setError('Please enter a valid 10-digit Indian mobile number.');
    setError(null);
    setSending(true);
    const slug = recommend(quiz, answers);
    const product = products.find((p) => p.slug === slug) ?? products[0];
    const res = await submitLead({
      name: name.trim(),
      phone: phone.trim(),
      product: product.lead_key,
      business_type: answers.business_type,
      size: answers.size,
      message: `Quiz: ${answers.business_type}, ${answers.size}, biggest headache: ${answers.headache}. Recommended: ${product.name}.`,
      source_widget: 'quiz',
      consent: true,
      website,
    });
    setSending(false);
    if (!res.ok) return setError(res.error ?? 'Something went wrong.');
    trackEvent('quiz_complete', { product: product.name, business_type: answers.business_type, size: answers.size });
    setResult(product);
  };

  if (result) {
    return (
      <div className="grid items-start gap-6 md:grid-cols-2" role="status">
        <div>
          <p className="text-sm font-semibold uppercase tracking-wide text-brand-700">Our recommendation</p>
          <h3 className="mt-2 text-2xl font-bold text-neutral-900">{result.name} fits your business best.</h3>
          <p className="mt-3 text-neutral-700">Thanks, {name.split(' ')[0]}. We have your details and will WhatsApp you. Want to talk now?</p>
          <div className="mt-5">
            <WhatsAppButton
              text={`Hi, I took the quiz on lalogix.com (${answers.business_type}, ${answers.size}). It recommended ${result.name}. Can you tell me more?`}
              label={`Ask about ${result.name} on WhatsApp`}
              location="quiz_result"
            />
          </div>
        </div>
        <ProductCard product={result} />
      </div>
    );
  }

  const q = questions[step];

  return (
    <div>
      <div className="mb-6 flex items-center gap-2" aria-hidden="true">
        {[0, 1, 2, 3].map((i) => (
          <span key={i} className={`h-1.5 flex-1 rounded-full ${i <= step ? 'bg-brand-700' : 'bg-neutral-200'}`} />
        ))}
      </div>
      <p className="text-sm text-neutral-600">Step {step + 1} of 4</p>

      {q ? (
        <fieldset className="mt-2">
          <legend className="text-xl font-semibold text-neutral-900">{q.label}</legend>
          <div className="mt-4 grid gap-3 sm:grid-cols-2">
            {q.options.map((opt) => (
              <button
                key={opt}
                type="button"
                onClick={() => choose(q.key, opt)}
                aria-pressed={answers[q.key] === opt}
                className="rounded-xl border border-neutral-300 bg-white px-4 py-3 text-left font-medium text-neutral-900 transition-colors hover:border-brand-600 hover:bg-brand-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-600"
              >
                {opt}
              </button>
            ))}
          </div>
          {step > 0 && (
            <button type="button" onClick={() => setStep((s) => s - 1)} className="mt-4 text-sm font-medium text-neutral-700 underline">
              Back
            </button>
          )}
        </fieldset>
      ) : (
        <form onSubmit={submit} noValidate className="mt-2">
          <h3 className="text-xl font-semibold text-neutral-900">Where should we send your recommendation?</h3>
          <div className="mt-4 grid gap-4 sm:grid-cols-2">
            <div>
              <label htmlFor="quiz-name" className="block text-sm font-medium text-neutral-800">
                Name
              </label>
              <input id="quiz-name" value={name} onChange={(e) => setName(e.target.value)} autoComplete="name" className="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-2.5" />
            </div>
            <div>
              <label htmlFor="quiz-phone" className="block text-sm font-medium text-neutral-800">
                Mobile number
              </label>
              <input
                id="quiz-phone"
                type="tel"
                inputMode="tel"
                value={phone}
                onChange={(e) => setPhone(e.target.value)}
                autoComplete="tel"
                className="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-2.5"
              />
            </div>
          </div>
          <input type="text" value={website} onChange={(e) => setWebsite(e.target.value)} tabIndex={-1} autoComplete="off" className="hidden" aria-hidden="true" />
          {error && (
            <p className="mt-3 text-sm text-red-700" role="alert">
              {error}
            </p>
          )}
          <p className="mt-3 text-xs text-neutral-600">
            We use your number only to reply about LALogix products (<Link href="/privacy-policy">privacy policy</Link>).
          </p>
          <div className="mt-4 flex flex-wrap gap-3">
            <button type="submit" disabled={sending} className={btn.primary}>
              {sending ? 'Finding your product…' : 'Show my recommendation'}
            </button>
            <button type="button" onClick={() => setStep(2)} className={btn.secondary}>
              Back
            </button>
          </div>
        </form>
      )}
    </div>
  );
}
