FROM dunglas/frankenphp

# Install PHP extensions and Git
RUN install-php-extensions \
    pcntl \
    mongodb && \
    apt-get update && \
    apt-get install -y git unzip

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Copy application code
COPY . /app

# Set working directory
WORKDIR /app

# Install Composer dependencies
RUN composer install --no-interaction --optimize-autoloader --no-dev

# Change APP_ENV and APP_DEBUG to be production-ready
RUN sed -i'' -e 's/^APP_ENV=.*/APP_ENV=production/' -e 's/^APP_DEBUG=.*/APP_DEBUG=false/' .env.example

# Copy the environment file
RUN cp .env.example .env

# Set permissions
RUN mkdir -p bootstrap/cache && \
    chown -R www-data:www-data bootstrap/cache

RUN chown -R www-data:www-data storage

RUN mkdir -p storage/logs && \
    chmod 777 storage/logs

# Generate the application key
RUN php artisan key:generate

# Set the entry point
ENTRYPOINT ["php", "artisan", "octane:frankenphp"]