import axios from 'axios';

export const createPortalClient = () => {
 const client = axios.create({
  baseURL: '/api/v1',
  withCredentials: true,
  timeout: 30_000,
  headers: {
   Accept: 'application/json',
   'X-Requested-With': 'XMLHttpRequest',
  },
 });

 return { client };
};

export const getPortalCsrf = () =>
 axios.get('/sanctum/csrf-cookie', { withCredentials: true, timeout: 30_000 });
