export type UserRole = "admin" | "viewer";

export type User = {
  id: number;
  name: string;
  email: string;
  role: UserRole;
  /** Portuguese label, coming from the backend enum. */
  role_label: string;
  /**
   * Whether the role can create, edit, import and record a payment.
   *
   * The screen uses this to hide what is pointless to offer. It is convenience,
   * not a barrier — the backend is what governs access, and it answers 403 to the
   * same operation even with no screen in the way.
   */
  can_write: boolean;
};

/** Laravel's response to POST /api/auth/login. */
export type LoginResponse = {
  token: string;
  user: User;
};

/** The Next Route Handler's response: the token does NOT go back to the browser. */
export type SessionResponse = {
  user: User;
};
