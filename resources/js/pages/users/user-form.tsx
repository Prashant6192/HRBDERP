import { Form } from '@inertiajs/react';
import { Field, FormSection } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { ErpUser, SelectOption } from '@/types';

export function UserForm({
    user,
    userRoles = [],
    roles,
    departments,
    statuses,
    canAssignRoles,
    action,
    submitLabel,
}: {
    user?: ErpUser;
    userRoles?: string[];
    roles: SelectOption[];
    departments: SelectOption[];
    statuses: SelectOption[];
    canAssignRoles: boolean;
    action: { url: string; method: 'post' | 'put' };
    submitLabel: string;
}) {
    const isEditing = user !== undefined;

    return (
        <Form
            action={action.url}
            method={action.method}
            options={{ preserveScroll: true }}
            className="space-y-6"
        >
            {({ errors, processing }) => (
                <>
                    <FormSection title="Employee">
                        <Field
                            label="Employee code"
                            htmlFor="employee_code"
                            error={errors.employee_code}
                        >
                            <Input
                                id="employee_code"
                                name="employee_code"
                                defaultValue={user?.employee_code ?? ''}
                                autoComplete="off"
                            />
                        </Field>

                        <Field
                            label="Full name"
                            htmlFor="name"
                            required
                            error={errors.name}
                        >
                            <Input
                                id="name"
                                name="name"
                                defaultValue={user?.name ?? ''}
                                required
                            />
                        </Field>

                        <Field
                            label="Email"
                            htmlFor="email"
                            required
                            error={errors.email}
                        >
                            <Input
                                id="email"
                                name="email"
                                type="email"
                                defaultValue={user?.email ?? ''}
                                required
                            />
                        </Field>

                        <Field
                            label="Department"
                            htmlFor="department_id"
                            error={errors.department_id}
                        >
                            <Select
                                name="department_id"
                                defaultValue={
                                    user?.department_id
                                        ? String(user.department_id)
                                        : undefined
                                }
                            >
                                <SelectTrigger
                                    id="department_id"
                                    className="w-full"
                                >
                                    <SelectValue placeholder="Unassigned" />
                                </SelectTrigger>
                                <SelectContent>
                                    {departments.map((department) => (
                                        <SelectItem
                                            key={department.value}
                                            value={String(department.value)}
                                        >
                                            {department.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>

                        <Field
                            label="Designation"
                            htmlFor="designation"
                            error={errors.designation}
                        >
                            <Input
                                id="designation"
                                name="designation"
                                defaultValue={user?.designation ?? ''}
                            />
                        </Field>

                        <Field
                            label="Phone"
                            htmlFor="phone"
                            error={errors.phone}
                        >
                            <Input
                                id="phone"
                                name="phone"
                                defaultValue={user?.phone ?? ''}
                            />
                        </Field>

                        <Field
                            label="Status"
                            htmlFor="status"
                            required
                            error={errors.status}
                        >
                            <Select
                                name="status"
                                defaultValue={user?.status ?? 'active'}
                            >
                                <SelectTrigger id="status" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {statuses.map((status) => (
                                        <SelectItem
                                            key={status.value}
                                            value={String(status.value)}
                                        >
                                            {status.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                    </FormSection>

                    <FormSection
                        title="Password"
                        description={
                            isEditing
                                ? 'Leave blank to keep the current password.'
                                : 'The account will be created with this password.'
                        }
                    >
                        <Field
                            label="Password"
                            htmlFor="password"
                            required={!isEditing}
                            error={errors.password}
                        >
                            <Input
                                id="password"
                                name="password"
                                type="password"
                                autoComplete="new-password"
                                required={!isEditing}
                            />
                        </Field>

                        <Field
                            label="Confirm password"
                            htmlFor="password_confirmation"
                            required={!isEditing}
                            error={errors.password_confirmation}
                        >
                            <Input
                                id="password_confirmation"
                                name="password_confirmation"
                                type="password"
                                autoComplete="new-password"
                                required={!isEditing}
                            />
                        </Field>

                        <div className="flex items-start gap-3 sm:col-span-2">
                            <Checkbox
                                id="must_change_password"
                                name="must_change_password"
                                value="1"
                                defaultChecked={false}
                            />
                            <div className="space-y-1">
                                <Label htmlFor="must_change_password">
                                    Require a password change at next sign-in
                                </Label>
                            </div>
                        </div>
                    </FormSection>

                    {canAssignRoles ? (
                        <FormSection
                            title="Roles"
                            description="What this person can do in the ERP. Every permission is enforced on the server."
                        >
                            <div className="grid gap-3 sm:col-span-2 sm:grid-cols-2">
                                {roles.map((role) => (
                                    <label
                                        key={role.value}
                                        className="hover:bg-muted/50 flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors"
                                    >
                                        <Checkbox
                                            name="roles[]"
                                            value={String(role.value)}
                                            defaultChecked={userRoles.includes(
                                                String(role.value),
                                            )}
                                        />
                                        <span className="space-y-1">
                                            <span className="block text-sm font-medium">
                                                {role.label}
                                            </span>
                                            {role.description && (
                                                <span className="text-muted-foreground block text-xs">
                                                    {role.description}
                                                </span>
                                            )}
                                        </span>
                                    </label>
                                ))}
                            </div>
                        </FormSection>
                    ) : (
                        <section className="bg-muted/40 text-muted-foreground rounded-xl border border-dashed p-5 text-sm">
                            Only a Super Admin can change which roles an account
                            holds, and never their own.
                        </section>
                    )}

                    <Button type="submit" disabled={processing}>
                        {submitLabel}
                    </Button>
                </>
            )}
        </Form>
    );
}
