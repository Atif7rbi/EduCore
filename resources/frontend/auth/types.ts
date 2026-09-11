export type UserRole =
    | 'student'
    | 'teacher'
    | 'admin';

export type UserStatus =
    | 'active'
    | 'disabled'
    | string;

export interface AuthUser {
    id: string;
    name: string;
    email: string;
    role: UserRole;
    status: UserStatus;
    learner_profile_id: string | null;
}

export interface AuthUserPayload {
    user: AuthUser;
}

export interface LogoutPayload {
    authenticated: false;
}

export interface LoginCredentials {
    email: string;
    password: string;
}
