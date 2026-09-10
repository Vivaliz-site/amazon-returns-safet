import assert from 'node:assert/strict';
import { classifyAmazonAuthState } from '../scripts/amazon-returns/seller-central-auth.mjs';

const normalPasswordPage = {
  href: 'https://sellercentral.amazon.com.br/ap/signin',
  title: 'Amazon Sign-In',
  text: 'Sign in\nPassword\nForgot password?\nKeep me signed in. Details\nCancel\nSign in with a passkey\nConditions of Use',
};

assert.equal(
  classifyAmazonAuthState(normalPasswordPage),
  'SIGN_IN',
  'An optional passkey link on the normal password page must not be treated as a human challenge.',
);

const trueIdentityChallenge = {
  href: 'https://sellercentral.amazon.com.br/ap/cvf',
  title: 'Verify your identity',
  text: 'Verify your identity before continuing',
};
assert.equal(classifyAmazonAuthState(trueIdentityChallenge), 'HUMAN_CHALLENGE');

console.log('seller-central-passkey-false-positive.test: OK');
