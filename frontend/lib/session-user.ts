import { cache } from "react";

import { fetchAsUser } from "@/lib/server-api";
import type { SessionResponse, User } from "@/types/auth";

/**
 * The session's user, once per request.
 *
 * React's `cache()` deduplicates per request: the authenticated layout and the
 * page it wraps both call this function and Laravel receives ONE call. Without
 * it, every screen that needed to know the role would add a round trip to
 * `/api/auth/me` that the layout had already made.
 */
export const getSessionUser = cache(async (): Promise<User> => {
  const { user } = await fetchAsUser<SessionResponse>("/api/auth/me");

  return user;
});
