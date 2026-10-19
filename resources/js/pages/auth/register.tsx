import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { PasswordField } from '@/components/form/PasswordField';
import { SubmitButton } from '@/components/form/SubmitButton';
import { TextInput } from '@/components/form/TextInput';
import TextLink from '@/components/text-link';
import { login } from '@/routes';
import { store } from '@/routes/register';

type Props = {
    passwordRules: string;
};

export default function Register({ passwordRules }: Props) {
    const form = useForm({ name: '', email: '', password: '', password_confirmation: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(store.url(), { onFinish: () => form.reset('password', 'password_confirmation') });
    }

    return (
        <>
            <Head title="Register" />

            <form onSubmit={submit} className="grid gap-5">
                <TextInput
                    name="name"
                    label="Full name"
                    required
                    autoFocus
                    autoComplete="name"
                    placeholder="Jane Doe"
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    error={form.errors.name}
                />

                <TextInput
                    name="email"
                    label="Email address"
                    type="email"
                    required
                    autoComplete="email"
                    placeholder="you@example.com"
                    value={form.data.email}
                    onChange={(e) => form.setData('email', e.target.value)}
                    error={form.errors.email}
                />

                <PasswordField
                    name="password"
                    label="Password"
                    required
                    autoComplete="new-password"
                    passwordrules={passwordRules}
                    value={form.data.password}
                    onChange={(e) => form.setData('password', e.target.value)}
                    error={form.errors.password}
                />

                <PasswordField
                    name="password_confirmation"
                    label="Confirm password"
                    required
                    autoComplete="new-password"
                    passwordrules={passwordRules}
                    value={form.data.password_confirmation}
                    onChange={(e) => form.setData('password_confirmation', e.target.value)}
                    error={form.errors.password_confirmation}
                />

                <SubmitButton processing={form.processing} className="w-full" data-test="register-user-button">
                    Create account
                </SubmitButton>
            </form>

            <p className="text-center text-sm text-muted-foreground">
                Already have an account? <TextLink href={login()}>Log in</TextLink>
            </p>
        </>
    );
}

Register.layout = {
    title: 'Create your account',
    description:
        'Sign up as a customer to raise and track support tickets. We will email you a link to confirm your address.',
};
