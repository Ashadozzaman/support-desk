import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { CheckboxInput } from '@/components/form/CheckboxInput';
import { PasswordField } from '@/components/form/PasswordField';
import { SubmitButton } from '@/components/form/SubmitButton';
import { TextInput } from '@/components/form/TextInput';
import PasskeyVerify from '@/components/passkey-verify';
import TextLink from '@/components/text-link';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
};

export default function Login({ status, canResetPassword }: Props) {
    const form = useForm({ email: '', password: '', remember: false });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(store.url(), { onFinish: () => form.reset('password') });
    }

    return (
        <>
            <Head title="Log in" />

            {status && (
                <div className="rounded-md bg-green-50 p-3 text-sm font-medium text-green-700 dark:bg-green-950 dark:text-green-300">
                    {status}
                </div>
            )}

            <PasskeyVerify />

            <form onSubmit={submit} className="grid gap-5">
                <TextInput
                    name="email"
                    label="Email address"
                    type="email"
                    required
                    autoFocus
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
                    autoComplete="current-password"
                    placeholder="Your password"
                    value={form.data.password}
                    onChange={(e) => form.setData('password', e.target.value)}
                    error={form.errors.password}
                    labelAction={
                        canResetPassword && <TextLink href={request()}>Forgot password?</TextLink>
                    }
                />

                <CheckboxInput
                    name="remember"
                    label="Keep me signed in"
                    checked={form.data.remember}
                    onChange={(checked) => form.setData('remember', checked)}
                />

                <SubmitButton processing={form.processing} className="w-full" data-test="login-button">
                    Log in
                </SubmitButton>
            </form>

            <p className="text-center text-sm text-muted-foreground">
                New here? <TextLink href={register()}>Create a customer account</TextLink>
            </p>
        </>
    );
}

Login.layout = {
    title: 'Welcome back',
    description: 'Log in to raise a ticket or check on an existing one.',
};
