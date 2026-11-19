// Entry point of the X-Ray console. Self-contained: Stimulus is vendored, so a host app needs no
// importmap or controllers.json changes.
import { Application } from './vendor/stimulus.js';
import ConsoleController from './controllers/console_controller.js';

const application = Application.start();
application.register('xray-console', ConsoleController);
