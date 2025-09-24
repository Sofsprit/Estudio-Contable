FROM dunglas/frankenphp

# Install PHP extensions and Git
RUN install-php-extensions \
    pcntl \
    mongodb && \
    apt-get update && \
    apt-get install -y git unzip curl gnupg supervisor

RUN apt-get update && apt-get install -y \
    chromium \
    chromium-driver \
    fonts-liberation \
    libatk-bridge2.0-0 \
    libatk1.0-0 \
    libx11-xcb1 \
    libxcomposite1 \
    libxdamage1 \
    libxfixes3 \
    libxrandr2 \
    libgbm1 \
    libasound2 \
    libpangocairo-1.0-0 \
    libnss3 \
    && ln -sf /usr/bin/chromium /usr/bin/chromium-browser

# Install Node.js (required for processing UDT files)
RUN curl -fsSL https://deb.nodesource.com/setup_24.x | bash - \
 && apt-get install -y nodejs \
 && npm install -g npm@latest

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Copy application code
COPY . /app

# Set working directory
WORKDIR /app

# Install Node.js dependencies
RUN cd scripts && npm install --omit=dev

# Install Composer dependencies
RUN composer install --no-interaction --optimize-autoloader --no-dev --no-scripts

# Change APP_ENV and APP_DEBUG to be production-ready
RUN sed -i'' -e 's/^APP_ENV=.*/APP_ENV=production/' -e 's/^APP_DEBUG=.*/APP_DEBUG=false/' .env.example

# Copy the environment file
RUN cp .env.example .env

# Set permissions
RUN mkdir -p bootstrap/cache storage/logs \
 && chown -R www-data:www-data bootstrap/cache storage \
 && chmod -R 775 storage

RUN mkdir -p storage/logs && chmod 777 storage/logs

# Copy supervisor config
COPY deploy/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Supervisor runs both FrankenPHP + queue worker
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
